<?php

declare(strict_types=1);

namespace App\FizzBuzz\Infrastructure\Persistence;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Per-connection SQLite settings (docs/conception.md §6.5): SQLite does not store them in the database file.
 *
 * Autoconfigured as a DBAL middleware (doctrine.middleware tag); foreign keys come from DBAL's EnableForeignKeys.
 */
final class SqliteConnectionPragmas implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            /** Lock wait before SQLITE_BUSY, then StatisticsStoreUnavailable and degraded mode (§5.10). */
            private const string BUSY_TIMEOUT = 'PRAGMA busy_timeout = 200';

            /** A confirmed commit survives a power loss. */
            private const string SYNCHRONOUS = 'PRAGMA synchronous = FULL';

            /** 64 MiB: size the WAL file is truncated to after a checkpoint. */
            private const string JOURNAL_SIZE_LIMIT = 'PRAGMA journal_size_limit = 67108864';

            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                $connection = parent::connect($params);
                $connection->exec(self::BUSY_TIMEOUT);
                $connection->exec(self::SYNCHRONOUS);
                $connection->exec(self::JOURNAL_SIZE_LIMIT);

                return $connection;
            }
        };
    }
}
