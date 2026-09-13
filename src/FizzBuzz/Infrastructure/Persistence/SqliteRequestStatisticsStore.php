<?php

declare(strict_types=1);

namespace App\FizzBuzz\Infrastructure\Persistence;

use App\FizzBuzz\Application\Exception\StatisticsStoreUnavailable;
use App\FizzBuzz\Application\Model\RequestStatistics;
use App\FizzBuzz\Application\Port\RequestStatisticsStore;
use App\FizzBuzz\Domain\FizzBuzzParameters;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\ParameterType;

/**
 * Sliding window of the last N calls in SQLite (docs/conception.md §6.2 to §6.4).
 *
 * The SQL is the one measured by docs/benchmarks/lib.php, byte for byte (checked by SqliteRequestStatisticsStoreTest).
 */
final class SqliteRequestStatisticsStore implements RequestStatisticsStore
{
    private const string SQL_UPSERT = 'INSERT INTO fizzbuzz_request_stat (int1, int2, limit_value, str1, str2, hits) VALUES (?, ?, ?, ?, ?, 1) '
        .'ON CONFLICT (int1, int2, limit_value, str1, str2) DO UPDATE SET hits = hits + 1 RETURNING id';

    private const string SQL_LOG = 'INSERT INTO fizzbuzz_request_log (stat_id) VALUES (?)';

    private const string SQL_MAX_LOG_ID = 'SELECT max(id) FROM fizzbuzz_request_log';

    private const string SQL_EVICT_DECREMENT = 'UPDATE fizzbuzz_request_stat '
        .'SET hits = hits - (SELECT count(*) FROM fizzbuzz_request_log l WHERE l.stat_id = fizzbuzz_request_stat.id AND l.id <= :threshold) '
        .'WHERE id IN (SELECT stat_id FROM fizzbuzz_request_log WHERE id <= :threshold)';

    private const string SQL_EVICT_LOG = 'DELETE FROM fizzbuzz_request_log WHERE id <= :threshold';

    private const string SQL_EVICT_STAT = 'DELETE FROM fizzbuzz_request_stat WHERE hits = 0';

    private const string SQL_READ = 'SELECT s.int1, s.int2, s.limit_value, s.str1, s.str2, s.hits, '
        .'coalesce((SELECT max(id) FROM fizzbuzz_request_log) - (SELECT min(id) FROM fizzbuzz_request_log) + 1, 0) AS window_count '
        .'FROM fizzbuzz_request_stat s ORDER BY s.hits DESC, s.id ASC LIMIT 1';

    /**
     * Primary SQLite result codes of an environment failure (§5.10): lock, read-only file, full disk, unreadable file.
     * Compared on code & 0xFF to cover the extended codes (SQLITE_IOERR_*, SQLITE_BUSY_SNAPSHOT, SQLITE_CANTOPEN_*).
     * Any other code (SQLITE_ERROR, SQLITE_LOCKED, SQLITE_CONSTRAINT...) is a programming error and propagates.
     */
    private const int SQLITE_PERM = 3;
    private const int SQLITE_BUSY = 5;
    private const int SQLITE_READONLY = 8;
    private const int SQLITE_IOERR = 10;
    private const int SQLITE_CORRUPT = 11;
    private const int SQLITE_FULL = 13;
    private const int SQLITE_CANTOPEN = 14;
    private const int SQLITE_PROTOCOL = 15;
    private const int SQLITE_NOTADB = 26;

    private const array UNAVAILABLE_CODES = [
        self::SQLITE_PERM,
        self::SQLITE_BUSY,
        self::SQLITE_READONLY,
        self::SQLITE_IOERR,
        self::SQLITE_CORRUPT,
        self::SQLITE_FULL,
        self::SQLITE_CANTOPEN,
        self::SQLITE_PROTOCOL,
        self::SQLITE_NOTADB,
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly int $windowSize,
    ) {
        if ($windowSize < 1) {
            throw new \InvalidArgumentException(\sprintf('The window size must be greater than or equal to 1, got %d.', $windowSize));
        }
    }

    /**
     * Service factory: STATS_WINDOW_SIZE is read as a raw string, because %env(int:)% would truncate "1.5" to 1.
     *
     * @throws \InvalidArgumentException unless the value is a decimal integer >= 1 that fits in an int
     */
    public static function withConfiguredWindowSize(Connection $connection, string $configuredWindowSize): self
    {
        if (1 !== preg_match('/^[1-9][0-9]*\z/', $configuredWindowSize) || (string) (int) $configuredWindowSize !== $configuredWindowSize) {
            throw new \InvalidArgumentException(\sprintf('STATS_WINDOW_SIZE must be a positive integer written in decimal, got "%s".', $configuredWindowSize));
        }

        return new self($connection, (int) $configuredWindowSize);
    }

    public function windowSize(): int
    {
        return $this->windowSize;
    }

    /**
     * Written by hand rather than with Connection::transactional(): when SQLite has already rolled the transaction back
     * (RAISE(ROLLBACK), some SQLITE_FULL), the rollBack() of transactional() throws "There is no active transaction"
     * instead of the real error.
     */
    public function record(FizzBuzzParameters $parameters): void
    {
        try {
            $this->connection->beginTransaction();
            try {
                // The UPSERT comes first: the transaction takes the write lock at once (§6.3).
                $statId = $this->connection->fetchOne(
                    self::SQL_UPSERT,
                    [$parameters->int1, $parameters->int2, $parameters->limit, $parameters->str1, $parameters->str2],
                    [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING],
                );
                $this->connection->executeStatement(self::SQL_LOG, [self::integer($statId, 'id')], [ParameterType::INTEGER]);
                $this->evict();
                $this->connection->commit();
            } catch (\Throwable $failure) {
                // A failed commit() ends DBAL's transaction count, but SQLite keeps the transaction open after SQLITE_BUSY:
                // the raw ROLLBACK closes it, otherwise the next call would fail on "There is already an active transaction".
                $this->rollBackIgnoringFailure($this->connection->isTransactionActive()
                    ? fn () => $this->connection->rollBack()
                    : fn () => $this->connection->executeStatement('ROLLBACK'));

                throw $failure;
            }
        } catch (DriverException $failure) {
            throw self::translate($failure);
        }
    }

    public function findMostFrequent(): RequestStatistics
    {
        try {
            $row = $this->connection->fetchAssociative(self::SQL_READ);
        } catch (DriverException $failure) {
            throw self::translate($failure);
        }

        if (false === $row) {
            return new RequestStatistics(null, 0, $this->windowSize, 0);
        }

        return new RequestStatistics(
            new FizzBuzzParameters(
                self::integer($row['int1'], 'int1'),
                self::integer($row['int2'], 'int2'),
                self::integer($row['limit_value'], 'limit_value'),
                self::string($row['str1'], 'str1'),
                self::string($row['str2'], 'str2'),
            ),
            self::integer($row['hits'], 'hits'),
            $this->windowSize,
            self::integer($row['window_count'], 'window_count'),
        );
    }

    /**
     * Applies the configured window size to the stored calls, at startup (§6.6). Outside the port: storage maintenance.
     *
     * BEGIN IMMEDIATE takes the write lock at once, because the first statement is a read. DBAL does not track this
     * raw transaction, so the rollback is attempted unconditionally and its failure ignored.
     *
     * @throws StatisticsStoreUnavailable
     */
    public function applyWindowSize(): void
    {
        try {
            $this->connection->executeStatement('BEGIN IMMEDIATE');
            try {
                $this->evict();
                $this->connection->executeStatement('COMMIT');
            } catch (\Throwable $failure) {
                $this->rollBackIgnoringFailure(fn () => $this->connection->executeStatement('ROLLBACK'));

                throw $failure;
            }
        } catch (DriverException $failure) {
            throw self::translate($failure);
        }
    }

    /**
     * Steps 3 to 6 of §6.3: the threshold is read once, and nothing is evicted below 1.
     */
    private function evict(): void
    {
        $maxId = $this->connection->fetchOne(self::SQL_MAX_LOG_ID);
        if (null === $maxId) {
            return;
        }

        $threshold = self::integer($maxId, 'max(id)') - $this->windowSize;
        if ($threshold < 1) {
            return;
        }

        $this->connection->executeStatement(self::SQL_EVICT_DECREMENT, ['threshold' => $threshold], ['threshold' => ParameterType::INTEGER]);
        $this->connection->executeStatement(self::SQL_EVICT_LOG, ['threshold' => $threshold], ['threshold' => ParameterType::INTEGER]);
        $this->connection->executeStatement(self::SQL_EVICT_STAT);
    }

    /**
     * The original error matters, not the rollback's: SQLite may already have rolled the transaction back.
     *
     * @param callable(): mixed $rollBack
     */
    private function rollBackIgnoringFailure(callable $rollBack): void
    {
        try {
            $rollBack();
        } catch (DbalException) {
        }
    }

    private static function translate(DriverException $failure): \Throwable
    {
        return \in_array($failure->getCode() & 0xFF, self::UNAVAILABLE_CODES, true)
            ? new StatisticsStoreUnavailable($failure)
            : $failure;
    }

    private static function integer(mixed $value, string $column): int
    {
        if (!\is_int($value)) {
            throw new \UnexpectedValueException(\sprintf('Expected an integer for %s, got %s.', $column, get_debug_type($value)));
        }

        return $value;
    }

    private static function string(mixed $value, string $column): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException(\sprintf('Expected a string for %s, got %s.', $column, get_debug_type($value)));
        }

        return $value;
    }
}
