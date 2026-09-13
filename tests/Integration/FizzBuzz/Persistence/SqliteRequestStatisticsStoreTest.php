<?php

declare(strict_types=1);

namespace App\Tests\Integration\FizzBuzz\Persistence;

use App\FizzBuzz\Application\Exception\StatisticsStoreUnavailable;
use App\FizzBuzz\Application\Port\RequestStatisticsStore;
use App\FizzBuzz\Domain\FizzBuzzParameters;
use App\FizzBuzz\Infrastructure\Persistence\SqliteConnectionPragmas;
use App\FizzBuzz\Infrastructure\Persistence\SqliteRequestStatisticsStore;
use App\Tests\Contract\RequestStatisticsStoreContractTest;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SqliteTestDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\ReadOnlyException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Runs the port contract on SQLite, then what the port does not show (docs/conception.md §6, §9.1): table invariants,
 * rollback, failures, query plans, pragmas and the configured window size.
 */
#[CoversClass(SqliteRequestStatisticsStore::class)]
#[CoversClass(SqliteConnectionPragmas::class)]
final class SqliteRequestStatisticsStoreTest extends RequestStatisticsStoreContractTest
{
    /** ABORT undoes the statement and leaves the transaction open; ROLLBACK makes SQLite roll the transaction back itself. */
    private const string INJECTED_FAILURE = "CREATE TEMP TRIGGER inject_failure BEFORE DELETE ON fizzbuzz_request_log BEGIN SELECT RAISE(%s, 'injected'); END";

    private Connection $connection;

    /** @var list<Connection> */
    private array $openedConnections = [];

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        $this->connection = SqliteTestDatabase::kernelConnection();
        SqliteTestDatabase::clear($this->connection);
    }

    protected function tearDown(): void
    {
        foreach ($this->openedConnections as $connection) {
            $connection->close();
        }
        foreach ($this->temporaryFiles as $path) {
            SqliteTestDatabase::remove($path);
        }
    }

    /**
     * Tables cleared by setUp(), adapter on the connection of the test kernel.
     */
    protected function createStore(int $windowSize): RequestStatisticsStore
    {
        return new SqliteRequestStatisticsStore($this->connection, $windowSize);
    }

    public function testTheTableInvariantsHoldAfterEveryCall(): void
    {
        $calls = ['N = 5' => [5, 'ABACDDBAACCBDABBADCCABDDACBABCDADBCAACBD'], 'then N = 1' => [1, 'DDABCCABDACBBADCABDC']];

        foreach ($calls as $phase => [$windowSize, $sequence]) {
            $store = new SqliteRequestStatisticsStore($this->connection, $windowSize);
            foreach (str_split($sequence) as $index => $key) {
                $store->record(self::combination($key));

                self::assertSame([], SqliteTestDatabase::invariantViolations($this->connection, $windowSize), \sprintf('%s, after call %d', $phase, $index + 1));
            }
        }
    }

    /**
     * N = 1: the second call evicts the first, and the injected trigger fails the log deletion, after the UPSERT,
     * the log insertion and the decrement. The error is a programming error (constraint): it is not translated.
     * With RAISE(ROLLBACK), SQLite has already rolled back and DBAL's rollBack() fails: the original error must win.
     */
    #[DataProvider('failingSecondCalls')]
    public function testAFailureInsideTheTransactionRollsEverythingBack(string $first, string $second, string $raise): void
    {
        $connection = $this->openTestDatabase();
        $store = new SqliteRequestStatisticsStore($connection, 1);
        $store->record(self::combination($first));
        $before = SqliteTestDatabase::snapshot($connection);
        $connection->executeStatement(\sprintf(self::INJECTED_FAILURE, $raise));

        $thrown = self::thrownBy(static fn () => $store->record(self::combination($second)));

        self::assertInstanceOf(DriverException::class, $thrown);
        self::assertSame(19, $thrown->getCode() & 0xFF, 'SQLITE_CONSTRAINT');
        self::assertFalse($connection->isTransactionActive());
        self::assertSame($before, SqliteTestDatabase::snapshot($connection));

        $connection->executeStatement('DROP TRIGGER temp.inject_failure');
        $store->record(self::combination($second));
        self::assertSame([], SqliteTestDatabase::invariantViolations($connection, 1));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function failingSecondCalls(): iterable
    {
        foreach (['ABORT', 'ROLLBACK'] as $raise) {
            yield "new combination (INSERT branch), RAISE($raise)" => ['A', 'B', $raise];
            yield "same combination (DO UPDATE branch, hits stays 1), RAISE($raise)" => ['A', 'A', $raise];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function raiseResolutions(): iterable
    {
        yield 'RAISE(ABORT)' => ['ABORT'];
        yield 'RAISE(ROLLBACK)' => ['ROLLBACK'];
    }

    public function testAFullDiskMakesRecordUnavailableAndChangesNothing(): void
    {
        $connection = $this->openTestDatabase();
        $store = new SqliteRequestStatisticsStore($connection, 5);
        $store->record(self::combination('A'));
        $before = SqliteTestDatabase::snapshot($connection);
        $connection->executeStatement(\sprintf('PRAGMA max_page_count = %d', self::integer($connection->fetchOne('PRAGMA page_count'))));
        $large = str_repeat('x', 1_000_000);

        $thrown = self::thrownBy(static fn () => $store->record(new FizzBuzzParameters(3, 5, 15, $large, $large)));

        self::assertInstanceOf(StatisticsStoreUnavailable::class, $thrown);
        $cause = $thrown->getPrevious();
        self::assertInstanceOf(DriverException::class, $cause);
        self::assertSame(13, $cause->getCode() & 0xFF, 'SQLITE_FULL');
        self::assertFalse($connection->isTransactionActive());
        self::assertSame($before, SqliteTestDatabase::snapshot($connection));

        $connection->executeStatement('PRAGMA max_page_count = 4294967294');
        $store->record(self::combination('B'));
        self::assertSame([], SqliteTestDatabase::invariantViolations($connection, 5));
    }

    /**
     * DBAL counts its transaction as ended even when COMMIT fails, while SQLite keeps it open after SQLITE_BUSY.
     * Rollback journal (not WAL): a reader with an unfinished SELECT holds the lock that COMMIT needs. Once the reader is
     * gone, the same instance must record again, instead of failing on "There is already an active transaction".
     */
    public function testACommitFailingWithBusyLeavesNoTransactionOpen(): void
    {
        // Own file: the schema of the specification without the WAL migration, hence a rollback journal.
        $path = SqliteTestDatabase::temporaryPath();
        $this->temporaryFiles[] = $path;
        $connection = SqliteTestDatabase::connectionTo($path);
        $this->openedConnections[] = $connection;
        require_once SqliteTestDatabase::projectDir().'/docs/benchmarks/lib.php';
        foreach (SCHEMA as $statement) {
            $connection->executeStatement($statement);
        }
        $store = new SqliteRequestStatisticsStore($connection, 5);
        $store->record(self::combination('A'));
        $before = SqliteTestDatabase::snapshot($connection);
        $reader = new \Pdo\Sqlite('sqlite:'.$path);
        $pendingRead = $reader->query('SELECT id FROM fizzbuzz_request_log UNION ALL SELECT 1');
        \assert(false !== $pendingRead);
        $pendingRead->fetch();

        try {
            $thrown = self::thrownBy(static fn () => $store->record(self::combination('B')));
        } finally {
            $pendingRead->closeCursor();
            unset($pendingRead, $reader);
        }

        self::assertInstanceOf(StatisticsStoreUnavailable::class, $thrown);
        $cause = $thrown->getPrevious();
        self::assertInstanceOf(DriverException::class, $cause);
        self::assertSame(5, $cause->getCode() & 0xFF, 'SQLITE_BUSY');
        self::assertSame($before, SqliteTestDatabase::snapshot($connection));

        $store->record(self::combination('B'));
        self::assertSame([], SqliteTestDatabase::invariantViolations($connection, 5));
    }

    public function testALockHeldByAnotherWriterMakesRecordUnavailableAfterTheBusyTimeout(): void
    {
        $store = new SqliteRequestStatisticsStore($this->connection, 5);
        $store->record(self::combination('A'));
        $before = SqliteTestDatabase::snapshot($this->connection);
        $locker = new \Pdo\Sqlite('sqlite:'.SqliteTestDatabase::kernelDatabasePath());
        $locker->exec('BEGIN IMMEDIATE');

        try {
            $locker->exec("INSERT INTO fizzbuzz_request_stat (int1, int2, limit_value, str1, str2, hits) VALUES (9, 9, 9, 'lock', 'lock', 1)");
            $start = hrtime(true);
            $thrown = self::thrownBy(static fn () => $store->record(self::combination('B')));
            $elapsedMs = (hrtime(true) - $start) / 1e6;

            self::assertInstanceOf(StatisticsStoreUnavailable::class, $thrown);
            self::assertInstanceOf(LockWaitTimeoutException::class, $thrown->getPrevious());
            self::assertGreaterThanOrEqual(150, $elapsedMs);
            self::assertLessThan(1_000, $elapsedMs);
            self::assertFalse($this->connection->isTransactionActive());
            $this->assertStatistics($store->findMostFrequent(), self::combination('A'), hits: 1, windowSize: 5, windowCount: 1);
        } finally {
            $locker->exec('ROLLBACK');
        }

        self::assertSame($before, SqliteTestDatabase::snapshot($this->connection));
        $store->record(self::combination('B'));
        self::assertSame([], SqliteTestDatabase::invariantViolations($this->connection, 5));
    }

    /**
     * The read-only flag cannot be lifted on an open connection: the same instance must still read after the failure.
     */
    public function testAReadOnlyDatabaseMakesRecordUnavailableImmediately(): void
    {
        (new SqliteRequestStatisticsStore($this->connection, 5))->record(self::combination('A'));
        $before = SqliteTestDatabase::snapshot($this->connection);
        $readOnly = $this->openTestDatabase([\Pdo\Sqlite::ATTR_OPEN_FLAGS => \Pdo\Sqlite::OPEN_READONLY]);
        $store = new SqliteRequestStatisticsStore($readOnly, 5);

        $start = hrtime(true);
        $thrown = self::thrownBy(static fn () => $store->record(self::combination('B')));
        $elapsedMs = (hrtime(true) - $start) / 1e6;

        self::assertInstanceOf(StatisticsStoreUnavailable::class, $thrown);
        self::assertInstanceOf(ReadOnlyException::class, $thrown->getPrevious());
        self::assertLessThan(100, $elapsedMs);
        self::assertFalse($readOnly->isTransactionActive());
        self::assertSame($before, SqliteTestDatabase::snapshot($this->connection));
        $this->assertStatistics($store->findMostFrequent(), self::combination('A'), hits: 1, windowSize: 5, windowCount: 1);
    }

    public function testADatabaseThatCannotBeOpenedMakesBothMethodsUnavailable(): void
    {
        $path = \sprintf('%s/fizzbuzz-missing-%s/app.db', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $connection = SqliteTestDatabase::connectionTo($path);
        $this->openedConnections[] = $connection;
        $store = new SqliteRequestStatisticsStore($connection, 5);

        self::assertInstanceOf(StatisticsStoreUnavailable::class, self::thrownBy(static fn () => $store->record(self::combination('A'))));
        self::assertInstanceOf(StatisticsStoreUnavailable::class, self::thrownBy(static fn () => $store->findMostFrequent()));
        self::assertFalse($connection->isTransactionActive());
    }

    public function testAMissingSchemaIsAProgrammingErrorAndPropagates(): void
    {
        $path = SqliteTestDatabase::temporaryPath();
        $this->temporaryFiles[] = $path;
        $connection = SqliteTestDatabase::connectionTo($path);
        $this->openedConnections[] = $connection;
        $store = new SqliteRequestStatisticsStore($connection, 5);

        self::assertInstanceOf(TableNotFoundException::class, self::thrownBy(static fn () => $store->record(self::combination('A'))));
        self::assertInstanceOf(TableNotFoundException::class, self::thrownBy(static fn () => $store->findMostFrequent()));
        self::assertFalse($connection->isTransactionActive());
    }

    public function testApplyWindowSizeEvictsTheOldestCallsAndLeavesNoTransaction(): void
    {
        $store = new SqliteRequestStatisticsStore($this->connection, 5);
        foreach (str_split('AABBC') as $key) {
            $store->record(self::combination($key));
        }

        $reduced = new SqliteRequestStatisticsStore($this->connection, 2);
        $reduced->applyWindowSize();

        self::assertFalse($this->connection->isTransactionActive());
        self::assertSame([], SqliteTestDatabase::invariantViolations($this->connection, 2));
        $this->assertStatistics($reduced->findMostFrequent(), self::combination('B'), hits: 1, windowSize: 2, windowCount: 2);
    }

    #[DataProvider('raiseResolutions')]
    public function testApplyWindowSizeRollsBackOnFailureAndCanRunAgain(string $raise): void
    {
        $connection = $this->openTestDatabase();
        $store = new SqliteRequestStatisticsStore($connection, 5);
        foreach (str_split('AABBC') as $key) {
            $store->record(self::combination($key));
        }
        $before = SqliteTestDatabase::snapshot($connection);
        $connection->executeStatement(\sprintf(self::INJECTED_FAILURE, $raise));
        $reduced = new SqliteRequestStatisticsStore($connection, 2);

        $thrown = self::thrownBy(static fn () => $reduced->applyWindowSize());

        self::assertInstanceOf(DriverException::class, $thrown);
        self::assertSame(19, $thrown->getCode() & 0xFF, 'SQLITE_CONSTRAINT');
        self::assertFalse($connection->isTransactionActive());
        self::assertSame($before, SqliteTestDatabase::snapshot($connection));

        $connection->executeStatement('DROP TRIGGER temp.inject_failure');
        $reduced->applyWindowSize();
        self::assertSame([], SqliteTestDatabase::invariantViolations($connection, 2));
    }

    public function testApplyWindowSizeIsUnavailableWhileAnotherWriterHoldsTheLock(): void
    {
        $store = new SqliteRequestStatisticsStore($this->connection, 5);
        foreach (str_split('AABBC') as $key) {
            $store->record(self::combination($key));
        }
        $before = SqliteTestDatabase::snapshot($this->connection);
        $reduced = new SqliteRequestStatisticsStore($this->connection, 2);
        $locker = new \Pdo\Sqlite('sqlite:'.SqliteTestDatabase::kernelDatabasePath());
        $locker->exec('BEGIN IMMEDIATE');

        try {
            $thrown = self::thrownBy(static fn () => $reduced->applyWindowSize());

            self::assertInstanceOf(StatisticsStoreUnavailable::class, $thrown);
            self::assertInstanceOf(LockWaitTimeoutException::class, $thrown->getPrevious());
            self::assertFalse($this->connection->isTransactionActive());
        } finally {
            $locker->exec('ROLLBACK');
        }

        self::assertSame($before, SqliteTestDatabase::snapshot($this->connection));
        $reduced->applyWindowSize();
        self::assertSame([], SqliteTestDatabase::invariantViolations($this->connection, 2));
    }

    /**
     * docs/benchmarks/lib.php holds the SQL of docs/conception.md §6.3 and §6.4 that the benchmarks measured.
     */
    public function testTheAdapterRunsTheMeasuredSqlOfTheSpecification(): void
    {
        require_once SqliteTestDatabase::projectDir().'/docs/benchmarks/lib.php';

        $constants = (new \ReflectionClass(SqliteRequestStatisticsStore::class))->getConstants();
        $sql = array_filter($constants, static fn (string $name): bool => str_starts_with($name, 'SQL_'), \ARRAY_FILTER_USE_KEY);

        self::assertSame([
            'SQL_UPSERT' => \SQL_UPSERT,
            'SQL_LOG' => \SQL_LOG,
            'SQL_MAX_LOG_ID' => \SQL_MAX_LOG_ID,
            'SQL_EVICT_DECREMENT' => \SQL_EVICT_DECREMENT,
            'SQL_EVICT_LOG' => \SQL_EVICT_LOG,
            'SQL_EVICT_STAT' => \SQL_EVICT_STAT,
            'SQL_READ' => \SQL_READ,
        ], $sql);
    }

    /**
     * Plans of the statements actually executed (captured by the DBAL logging middleware), with their parameters,
     * on a full window: the eviction never scans, the read scans only the first entry of idx_fizzbuzz_hits (R03).
     */
    public function testTheEvictionAndTheReadNeverScanTheLog(): void
    {
        $logger = new RecordingLogger();
        $connection = $this->openTestDatabase(extraMiddlewares: [new LoggingMiddleware($logger)]);
        $store = new SqliteRequestStatisticsStore($connection, 5);
        foreach (str_split('ABCDA') as $key) {
            $store->record(self::combination($key));
        }
        $logger->records = [];

        $store->record(self::combination('B'));
        $store->findMostFrequent();

        $executed = self::executedStatements($logger);
        $decrement = self::onlyStatementStartingWith($executed, 'UPDATE fizzbuzz_request_stat SET hits = hits -');
        $logDeletion = self::onlyStatementStartingWith($executed, 'DELETE FROM fizzbuzz_request_log');
        $statDeletion = self::onlyStatementStartingWith($executed, 'DELETE FROM fizzbuzz_request_stat');
        $read = self::onlyStatementStartingWith($executed, 'SELECT s.int1');

        foreach ([$decrement, $logDeletion, $statDeletion] as $statement) {
            $plan = self::queryPlan($connection, $statement);
            self::assertSame([], self::scans($plan), $statement['sql'].' | '.implode(' | ', $plan));
        }

        $readPlan = self::queryPlan($connection, $read);
        self::assertSame(['SCAN s USING INDEX idx_fizzbuzz_hits'], self::scans($readPlan), implode(' | ', $readPlan));
        self::assertCount(2, array_keys($readPlan, 'SEARCH fizzbuzz_request_log', true), implode(' | ', $readPlan));
    }

    /**
     * §6.3: no eviction below a threshold of 1. With N = 5, the fifth call has a threshold of 0 and runs no eviction statement.
     */
    public function testNoEvictionStatementRunsWhileTheWindowFillsUp(): void
    {
        $logger = new RecordingLogger();
        $store = new SqliteRequestStatisticsStore($this->openTestDatabase(extraMiddlewares: [new LoggingMiddleware($logger)]), 5);

        foreach (str_split('ABCDA') as $key) {
            $store->record(self::combination($key));
        }

        $evictions = array_filter(
            self::executedStatements($logger),
            static fn (array $statement): bool => str_starts_with($statement['sql'], 'UPDATE') || str_starts_with($statement['sql'], 'DELETE'),
        );
        self::assertSame([], $evictions);
    }

    public function testRecordsSurviveReopeningTheDatabase(): void
    {
        $store = new SqliteRequestStatisticsStore($this->connection, 5);
        foreach (str_split('ABA') as $key) {
            $store->record(self::combination($key));
        }

        $this->connection->close();
        $reopened = $this->openTestDatabase();

        $this->assertStatistics((new SqliteRequestStatisticsStore($reopened, 5))->findMostFrequent(), self::combination('A'), hits: 2, windowSize: 5, windowCount: 3);
    }

    public function testANewKernelConnectionAppliesThePragmas(): void
    {
        $this->connection->close();

        self::assertSame(200, $this->connection->fetchOne('PRAGMA busy_timeout'));
        self::assertSame(2, $this->connection->fetchOne('PRAGMA synchronous'), 'FULL');
        self::assertSame(67_108_864, $this->connection->fetchOne('PRAGMA journal_size_limit'));
        self::assertSame(1, $this->connection->fetchOne('PRAGMA foreign_keys'));
        self::assertSame('wal', $this->connection->fetchOne('PRAGMA journal_mode'));
    }

    #[DataProvider('windowSizesBelowOne')]
    public function testRejectsAWindowSmallerThanOne(int $windowSize): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('The window size must be greater than or equal to 1, got %d.', $windowSize));

        new SqliteRequestStatisticsStore($this->connection, $windowSize);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function windowSizesBelowOne(): iterable
    {
        yield '0' => [0];
        yield '-1' => [-1];
    }

    #[DataProvider('validConfiguredWindowSizes')]
    public function testAcceptsAConfiguredWindowSizeWrittenInDecimal(string $configured, int $expected): void
    {
        self::assertSame($expected, SqliteRequestStatisticsStore::withConfiguredWindowSize($this->connection, $configured)->windowSize());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function validConfiguredWindowSizes(): iterable
    {
        yield '1' => ['1', 1];
        yield '100000' => ['100000', 100_000];
    }

    /**
     * %env(int:)% would truncate "1.5" to 1 and read "1e3" as 1000: the raw string is checked instead.
     */
    #[DataProvider('invalidConfiguredWindowSizes')]
    public function testRejectsAConfiguredWindowSizeThatIsNotAPositiveDecimalInteger(string $configured): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('STATS_WINDOW_SIZE must be a positive integer written in decimal, got "%s".', $configured));

        SqliteRequestStatisticsStore::withConfiguredWindowSize($this->connection, $configured);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidConfiguredWindowSizes(): iterable
    {
        foreach (['', '0', '-3', '1.5', '1e3', 'abc', ' 5', '5 ', "5\n", '+5', '007', '0x10', '99999999999999999999'] as $value) {
            yield json_encode($value, \JSON_THROW_ON_ERROR) => [$value];
        }
    }

    /**
     * @param array<int, mixed> $driverOptions
     * @param list<Middleware>  $extraMiddlewares
     */
    private function openTestDatabase(array $driverOptions = [], array $extraMiddlewares = []): Connection
    {
        $connection = SqliteTestDatabase::connectionTo(SqliteTestDatabase::kernelDatabasePath(), $driverOptions, $extraMiddlewares);
        $this->openedConnections[] = $connection;

        return $connection;
    }

    private static function combination(string $key): FizzBuzzParameters
    {
        return match ($key) {
            'A' => new FizzBuzzParameters(3, 5, 15, 'fizz', 'buzz'),
            'B' => new FizzBuzzParameters(2, 7, 30, 'foo', 'bar'),
            'C' => new FizzBuzzParameters(4, 6, 12, 'x', 'y'),
            'D' => new FizzBuzzParameters(3, 5, 15, '0', "\u{1F600}"),
            default => throw new \LogicException(\sprintf('Unknown combination "%s".', $key)),
        };
    }

    private static function thrownBy(callable $operation): \Throwable
    {
        try {
            $operation();
        } catch (\Throwable $thrown) {
            return $thrown;
        }

        self::fail('The operation was expected to throw.');
    }

    private static function integer(mixed $value): int
    {
        self::assertIsInt($value);

        return $value;
    }

    /**
     * @return list<array{sql: string, params: list<mixed>, types: list<mixed>}>
     */
    private static function executedStatements(RecordingLogger $logger): array
    {
        $executed = [];
        foreach ($logger->records as $record) {
            $sql = $record['context']['sql'] ?? null;
            if (!\is_string($sql)) {
                continue;
            }
            $params = $record['context']['params'] ?? [];
            $types = $record['context']['types'] ?? [];
            self::assertIsArray($params);
            self::assertIsArray($types);
            $executed[] = ['sql' => $sql, 'params' => array_values($params), 'types' => array_values($types)];
        }

        return $executed;
    }

    /**
     * @param list<array{sql: string, params: list<mixed>, types: list<mixed>}> $executed
     *
     * @return array{sql: string, params: list<mixed>, types: list<mixed>}
     */
    private static function onlyStatementStartingWith(array $executed, string $prefix): array
    {
        $matching = array_values(array_filter($executed, static fn (array $statement): bool => str_starts_with($statement['sql'], $prefix)));
        self::assertCount(1, $matching, \sprintf('Statements starting with "%s"', $prefix));

        return $matching[0];
    }

    /**
     * @param array{sql: string, params: list<mixed>, types: list<mixed>} $statement
     *
     * @return list<string>
     */
    private static function queryPlan(Connection $connection, array $statement): array
    {
        /** @var list<\Doctrine\DBAL\ParameterType> $types */
        $types = $statement['types'];
        $details = [];
        foreach ($connection->fetchAllAssociative('EXPLAIN QUERY PLAN '.$statement['sql'], $statement['params'], $types) as $row) {
            self::assertIsString($row['detail']);
            $details[] = $row['detail'];
        }

        return $details;
    }

    /**
     * @param list<string> $plan
     *
     * @return list<string>
     */
    private static function scans(array $plan): array
    {
        return array_values(array_filter($plan, static fn (string $detail): bool => str_starts_with($detail, 'SCAN')));
    }
}
