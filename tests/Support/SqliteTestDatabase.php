<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\FizzBuzz\Infrastructure\Persistence\SqliteConnectionPragmas;
use App\Kernel;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractSQLiteDriver\Middleware\EnableForeignKeys;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\DriverManager;

/**
 * SQLite test database (docs/conception.md §9.1): connection of the test kernel, standalone connections with the
 * application middlewares, table checks and console subprocesses.
 *
 * The test database (DATABASE_URL of .env.test) is prepared by `make test-db`.
 */
final class SqliteTestDatabase
{
    private static ?Kernel $kernel = null;

    /**
     * Test kernel booted once per PHPUnit process, with the real configuration and middlewares.
     */
    public static function kernel(): Kernel
    {
        if (null === self::$kernel) {
            self::$kernel = new Kernel('test', true);
            self::$kernel->boot();
        }

        return self::$kernel;
    }

    public static function kernelConnection(): Connection
    {
        // Public service, typed from the container by phpstan-symfony.
        return self::kernel()->getContainer()->get('doctrine.dbal.default_connection');
    }

    public static function kernelDatabasePath(): string
    {
        $path = self::kernelConnection()->getParams()['path'] ?? null;
        \assert(\is_string($path));

        return $path;
    }

    /**
     * Connection outside the kernel with the application middlewares, for failure and multi-process tests.
     *
     * @param array<int, mixed> $driverOptions    PDO options, for example the read-only open flag
     * @param list<Middleware>  $extraMiddlewares wrapped by the application middlewares
     */
    public static function connectionTo(string $path, array $driverOptions = [], array $extraMiddlewares = []): Connection
    {
        $configuration = (new Configuration())->setMiddlewares([...$extraMiddlewares, new EnableForeignKeys(), new SqliteConnectionPragmas()]);

        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path, 'driverOptions' => $driverOptions], $configuration);
    }

    /**
     * Log first: the foreign keys forbid deleting a referenced combination. Ids then start at 1 again, as in a new
     * database, so that the N / N + 1 boundary reaches the eviction threshold of exactly 1.
     */
    public static function clear(Connection $connection): void
    {
        $connection->executeStatement('DELETE FROM fizzbuzz_request_log');
        $connection->executeStatement('DELETE FROM fizzbuzz_request_stat');
        $connection->executeStatement("DELETE FROM sqlite_sequence WHERE name IN ('fizzbuzz_request_log', 'fizzbuzz_request_stat')");
    }

    /**
     * Both tables and their AUTOINCREMENT sequences, as seen by $connection (uncommitted changes included).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function snapshot(Connection $connection): array
    {
        return [
            'stat' => $connection->fetchAllAssociative('SELECT * FROM fizzbuzz_request_stat ORDER BY id'),
            'log' => $connection->fetchAllAssociative('SELECT * FROM fizzbuzz_request_log ORDER BY id'),
            'sequence' => $connection->fetchAllAssociative("SELECT name, seq FROM sqlite_sequence WHERE name LIKE 'fizzbuzz%' ORDER BY name"),
        ];
    }

    /**
     * Invariants 1 to 5 of docs/conception.md §6.3 checked on the tables, after docs/benchmarks/lib.php.
     *
     * @return list<string> the violated invariants, empty when all hold
     */
    public static function invariantViolations(Connection $connection, int $windowSize): array
    {
        $violations = [];
        $logRows = self::integer($connection, 'SELECT count(*) FROM fizzbuzz_request_log');
        $sumOfHits = self::integer($connection, 'SELECT coalesce(sum(hits), 0) FROM fizzbuzz_request_stat');

        if ($logRows > $windowSize) {
            $violations[] = \sprintf('1: %d log rows for a window of %d', $logRows, $windowSize);
        }
        if ($sumOfHits !== $logRows) {
            $violations[] = \sprintf('2: sum of hits %d, log rows %d', $sumOfHits, $logRows);
        }
        if (self::integer($connection, 'SELECT count(*) FROM fizzbuzz_request_stat s WHERE s.hits != (SELECT count(*) FROM fizzbuzz_request_log l WHERE l.stat_id = s.id)') > 0) {
            $violations[] = '3: hits different from the number of log references';
        }
        if (self::integer($connection, 'SELECT count(*) FROM fizzbuzz_request_stat WHERE hits = 0') > 0) {
            $violations[] = '4: combination with 0 hit';
        }
        if (self::integer($connection, 'SELECT count(*) FROM fizzbuzz_request_log l LEFT JOIN fizzbuzz_request_stat s ON s.id = l.stat_id WHERE s.id IS NULL') > 0) {
            $violations[] = '4: orphan log reference';
        }
        if (self::integer($connection, 'SELECT count(*) FROM (SELECT 1 FROM fizzbuzz_request_stat GROUP BY int1, int2, limit_value, str1, str2 HAVING count(*) > 1)') > 0) {
            $violations[] = '4: duplicate combination';
        }
        if (0 !== self::integer($connection, 'SELECT count(*) - coalesce(max(id) - min(id) + 1, 0) FROM fizzbuzz_request_log')) {
            $violations[] = '5: log ids not contiguous';
        }

        return $violations;
    }

    /**
     * Runs bin/console in a subprocess, in the test environment. Real environment variables win over .env.test.
     *
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     *
     * @return array{exitCode: int, output: string}
     */
    public static function console(array $arguments, array $environment = []): array
    {
        $output = tmpfile();
        \assert(false !== $output);
        $process = proc_open(
            [\PHP_BINARY, self::projectDir().'/bin/console', ...$arguments, '--env=test', '--no-interaction'],
            [0 => ['pipe', 'r'], 1 => $output, 2 => $output],
            $pipes,
            self::projectDir(),
            array_merge(getenv(), ['APP_ENV' => 'test', 'COLUMNS' => '1000'], $environment),
        );
        \assert(\is_resource($process));
        fclose($pipes[0]);
        $exitCode = proc_close($process);
        rewind($output);

        return ['exitCode' => $exitCode, 'output' => (string) stream_get_contents($output)];
    }

    /**
     * @return array{exitCode: int, output: string}
     */
    public static function migrate(string $path): array
    {
        return self::console(['doctrine:migrations:migrate'], ['DATABASE_URL' => self::urlOf($path)]);
    }

    public static function urlOf(string $path): string
    {
        return 'sqlite:///'.$path;
    }

    public static function temporaryPath(): string
    {
        return \sprintf('%s/fizzbuzz-test-%s.db', sys_get_temp_dir(), bin2hex(random_bytes(6)));
    }

    public static function remove(string $path): void
    {
        foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
            if (is_file($path.$suffix)) {
                unlink($path.$suffix);
            }
        }
    }

    public static function projectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    private static function integer(Connection $connection, string $sql): int
    {
        $value = $connection->fetchOne($sql);
        if (!\is_int($value)) {
            throw new \UnexpectedValueException(\sprintf('Expected an integer from "%s", got %s.', $sql, get_debug_type($value)));
        }

        return $value;
    }
}
