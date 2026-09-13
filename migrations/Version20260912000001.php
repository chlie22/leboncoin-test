<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Statistics window: combinations and call log (docs/conception.md §6.2).
 *
 * Raw SQL, identical to SCHEMA in docs/benchmarks/lib.php, rather than the Schema API: the measured schema is the one applied.
 */
final class Version20260912000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Statistics window: combinations, call log and their indexes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE fizzbuzz_request_stat (id INTEGER PRIMARY KEY AUTOINCREMENT, int1 INTEGER NOT NULL, int2 INTEGER NOT NULL, limit_value INTEGER NOT NULL, str1 VARCHAR(50) NOT NULL, str2 VARCHAR(50) NOT NULL, hits INTEGER NOT NULL CHECK (hits >= 0))');
        $this->addSql('CREATE UNIQUE INDEX uniq_fizzbuzz_request ON fizzbuzz_request_stat (int1, int2, limit_value, str1, str2)');
        $this->addSql('CREATE INDEX idx_fizzbuzz_hits ON fizzbuzz_request_stat (hits DESC, id ASC)');
        $this->addSql('CREATE TABLE fizzbuzz_request_log (id INTEGER PRIMARY KEY AUTOINCREMENT, stat_id INTEGER NOT NULL REFERENCES fizzbuzz_request_stat (id))');
        $this->addSql('CREATE INDEX idx_fizzbuzz_log_stat ON fizzbuzz_request_log (stat_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE fizzbuzz_request_log');
        $this->addSql('DROP TABLE fizzbuzz_request_stat');
    }
}
