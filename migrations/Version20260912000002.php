<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Write-ahead logging (docs/conception.md §6.5): reads are not blocked by a write.
 *
 * journal_mode is stored in the database file, unlike the per-connection pragmas of SqliteConnectionPragmas.
 * SQLite cannot change it inside a transaction.
 */
final class Version20260912000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Statistics database in WAL journal mode.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('PRAGMA journal_mode=WAL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('PRAGMA journal_mode=DELETE');
    }
}
