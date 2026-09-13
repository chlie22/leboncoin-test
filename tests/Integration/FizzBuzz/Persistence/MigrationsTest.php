<?php

declare(strict_types=1);

namespace App\Tests\Integration\FizzBuzz\Persistence;

use App\Tests\Support\SqliteTestDatabase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Migrations run as at container startup, in a subprocess, on a new database file (docs/conception.md §6.2, §6.5, §7.6).
 */
#[CoversNothing]
final class MigrationsTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = SqliteTestDatabase::temporaryPath();
    }

    protected function tearDown(): void
    {
        SqliteTestDatabase::remove($this->path);
    }

    public function testCreateTheSchemaOfTheSpecificationAndCanBeReplayed(): void
    {
        $first = SqliteTestDatabase::migrate($this->path);
        self::assertSame(0, $first['exitCode'], $first['output']);
        $schema = $this->schema();

        require_once SqliteTestDatabase::projectDir().'/docs/benchmarks/lib.php';
        self::assertSame(\SCHEMA, array_values(array_map(
            static fn (array $object): mixed => $object['sql'],
            array_filter($schema, static fn (array $object): bool => \is_string($object['name']) && str_contains($object['name'], 'fizzbuzz')),
        )));

        $second = SqliteTestDatabase::migrate($this->path);
        self::assertSame(0, $second['exitCode'], $second['output']);
        self::assertSame($schema, $this->schema());

        $upToDate = SqliteTestDatabase::console(['doctrine:migrations:up-to-date'], ['DATABASE_URL' => SqliteTestDatabase::urlOf($this->path)]);
        self::assertSame(0, $upToDate['exitCode'], $upToDate['output']);
    }

    public function testEnableWriteAheadLoggingPersistently(): void
    {
        $migration = SqliteTestDatabase::migrate($this->path);
        self::assertSame(0, $migration['exitCode'], $migration['output']);

        $connection = SqliteTestDatabase::connectionTo($this->path);
        try {
            self::assertSame('wal', $connection->fetchOne('PRAGMA journal_mode'));
        } finally {
            $connection->close();
        }
    }

    /**
     * Every schema object, in creation order.
     *
     * @return list<array<string, mixed>>
     */
    private function schema(): array
    {
        $connection = SqliteTestDatabase::connectionTo($this->path);
        try {
            return $connection->fetchAllAssociative('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY rowid');
        } finally {
            $connection->close();
        }
    }
}
