<?php

// Schéma et SQL de la fenêtre glissante, à l'identique de docs/conception.md §6.2 à §6.6.
// Utilisé uniquement par les scripts de mesure de ce répertoire : ce n'est pas le code de l'application.

declare(strict_types=1);

const SCHEMA = [
    'CREATE TABLE fizzbuzz_request_stat (id INTEGER PRIMARY KEY AUTOINCREMENT, int1 INTEGER NOT NULL, int2 INTEGER NOT NULL, limit_value INTEGER NOT NULL, str1 VARCHAR(50) NOT NULL, str2 VARCHAR(50) NOT NULL, hits INTEGER NOT NULL CHECK (hits >= 0))',
    'CREATE UNIQUE INDEX uniq_fizzbuzz_request ON fizzbuzz_request_stat (int1, int2, limit_value, str1, str2)',
    'CREATE INDEX idx_fizzbuzz_hits ON fizzbuzz_request_stat (hits DESC, id ASC)',
    'CREATE TABLE fizzbuzz_request_log (id INTEGER PRIMARY KEY AUTOINCREMENT, stat_id INTEGER NOT NULL REFERENCES fizzbuzz_request_stat (id))',
    'CREATE INDEX idx_fizzbuzz_log_stat ON fizzbuzz_request_log (stat_id)',
];

const SQL_UPSERT = 'INSERT INTO fizzbuzz_request_stat (int1, int2, limit_value, str1, str2, hits) VALUES (?, ?, ?, ?, ?, 1) '
    . 'ON CONFLICT (int1, int2, limit_value, str1, str2) DO UPDATE SET hits = hits + 1 RETURNING id';

const SQL_LOG = 'INSERT INTO fizzbuzz_request_log (stat_id) VALUES (?)';

const SQL_MAX_LOG_ID = 'SELECT max(id) FROM fizzbuzz_request_log';

// Éviction : seuil = max(id) - N, calculé une seule fois. La forme UPDATE ... WHERE id IN n'utilise que des recherches
// indexées. Une première version en UPDATE ... FROM avec agrégat parcourait tout l'index du journal (5,5 ms par appel).
const SQL_EVICT_DECREMENT = 'UPDATE fizzbuzz_request_stat '
    . 'SET hits = hits - (SELECT count(*) FROM fizzbuzz_request_log l WHERE l.stat_id = fizzbuzz_request_stat.id AND l.id <= :threshold) '
    . 'WHERE id IN (SELECT stat_id FROM fizzbuzz_request_log WHERE id <= :threshold)';

const SQL_EVICT_LOG = 'DELETE FROM fizzbuzz_request_log WHERE id <= :threshold';

const SQL_EVICT_STAT = 'DELETE FROM fizzbuzz_request_stat WHERE hits = 0';

const SQL_READ = 'SELECT s.int1, s.int2, s.limit_value, s.str1, s.str2, s.hits, '
    . 'coalesce((SELECT max(id) FROM fizzbuzz_request_log) - (SELECT min(id) FROM fizzbuzz_request_log) + 1, 0) AS window_count '
    . 'FROM fizzbuzz_request_stat s ORDER BY s.hits DESC, s.id ASC LIMIT 1';

// Ancienne forme de lecture, conservée pour comparaison (review R03).
const SQL_READ_COMBINED = 'SELECT s.int1, s.int2, s.limit_value, s.str1, s.str2, s.hits, '
    . '(SELECT coalesce(max(id) - min(id) + 1, 0) FROM fizzbuzz_request_log) AS window_count '
    . 'FROM fizzbuzz_request_stat s ORDER BY s.hits DESC, s.id ASC LIMIT 1';

function openDatabase(string $name, string $synchronous = 'FULL'): array
{
    $file = sys_get_temp_dir() . '/fizzbuzz-bench-' . $name . '.db';
    removeDatabase($file);
    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA synchronous=' . $synchronous);
    $pdo->exec('PRAGMA foreign_keys=ON');

    return [$pdo, $file];
}

function removeDatabase(string $file): void
{
    foreach (['', '-wal', '-shm'] as $suffix) {
        @unlink($file . $suffix);
    }
}

function createSchema(PDO $pdo): void
{
    foreach (SCHEMA as $statement) {
        $pdo->exec($statement);
    }
}

function databaseSizeMb(PDO $pdo, string $file): float
{
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    clearstatcache();

    return ((filesize($file) ?: 0) + (@filesize($file . '-wal') ?: 0)) / 1e6;
}

function averageMs(callable $operation, int $iterations): float
{
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; ++$i) {
        $operation($i);
    }

    return (hrtime(true) - $start) / 1e6 / $iterations;
}

function queryPlan(PDO $pdo, string $sql, array $parameters = []): string
{
    $statement = $pdo->prepare('EXPLAIN QUERY PLAN ' . $sql);
    $statement->execute($parameters);

    return implode(' | ', array_column($statement->fetchAll(PDO::FETCH_ASSOC), 'detail'));
}

function environment(): string
{
    $sqlite = (new PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn();

    return sprintf('PHP %s, SQLite %s, %s %s %s', PHP_VERSION, $sqlite, php_uname('s'), php_uname('r'), php_uname('m'));
}

final class WindowStore
{
    private PDOStatement $upsert;
    private PDOStatement $log;
    private PDOStatement $maxLogId;
    private PDOStatement $evictDecrement;
    private PDOStatement $evictLog;
    private PDOStatement $evictStat;
    private PDOStatement $read;

    public function __construct(private readonly PDO $pdo, private int $windowSize)
    {
        $this->upsert = $pdo->prepare(SQL_UPSERT);
        $this->log = $pdo->prepare(SQL_LOG);
        $this->maxLogId = $pdo->prepare(SQL_MAX_LOG_ID);
        $this->evictDecrement = $pdo->prepare(SQL_EVICT_DECREMENT);
        $this->evictLog = $pdo->prepare(SQL_EVICT_LOG);
        $this->evictStat = $pdo->prepare(SQL_EVICT_STAT);
        $this->read = $pdo->prepare(SQL_READ);
    }

    public function setWindowSize(int $windowSize): void
    {
        $this->windowSize = $windowSize;
    }

    /** Enregistrement d'un appel (§6.3) : BEGIN différé, première instruction = écriture (UPSERT). */
    public function record(int $int1, int $int2, int $limit, string $str1, string $str2): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->upsert->execute([$int1, $int2, $limit, $str1, $str2]);
            $statId = $this->upsert->fetchColumn();
            $this->upsert->closeCursor();
            $this->log->execute([$statId]);
            $this->evict();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Application de la taille de fenêtre au démarrage (§6.6). La première instruction étant une lecture (seuil),
     * BEGIN IMMEDIATE prend le verrou d'écriture d'emblée.
     */
    public function applyWindowSize(): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->evict();
            $this->pdo->exec('COMMIT');
        } catch (Throwable $exception) {
            $this->pdo->exec('ROLLBACK');
            throw $exception;
        }
    }

    public function read(): ?array
    {
        $this->read->execute();
        $row = $this->read->fetch(PDO::FETCH_ASSOC);
        $this->read->closeCursor();

        return false === $row ? null : $row;
    }

    private function evict(): void
    {
        $this->maxLogId->execute();
        $maxId = $this->maxLogId->fetchColumn();
        $this->maxLogId->closeCursor();
        if (null === $maxId || false === $maxId) {
            return;
        }
        $threshold = (int) $maxId - $this->windowSize;
        if ($threshold < 1) {
            return;
        }
        $this->evictDecrement->execute([':threshold' => $threshold]);
        $this->evictLog->execute([':threshold' => $threshold]);
        $this->evictStat->execute();
    }
}

/** Contrôle des invariants du §6.3 ; renvoie la liste des invariants violés. */
function invariantViolations(PDO $pdo, int $windowSize): array
{
    $violations = [];
    $logCount = (int) $pdo->query('SELECT count(*) FROM fizzbuzz_request_log')->fetchColumn();
    $sumHits = (int) $pdo->query('SELECT coalesce(sum(hits), 0) FROM fizzbuzz_request_stat')->fetchColumn();
    if ($logCount > $windowSize) {
        $violations[] = "journal ($logCount) > N ($windowSize)";
    }
    if ($sumHits !== $logCount) {
        $violations[] = "somme des hits ($sumHits) != journal ($logCount)";
    }
    if ((int) $pdo->query('SELECT count(*) FROM fizzbuzz_request_stat WHERE hits = 0')->fetchColumn() > 0) {
        $violations[] = 'combinaison à 0 hit';
    }
    if ((int) $pdo->query('SELECT count(*) FROM fizzbuzz_request_log l LEFT JOIN fizzbuzz_request_stat s ON s.id = l.stat_id WHERE s.id IS NULL')->fetchColumn() > 0) {
        $violations[] = 'référence orpheline';
    }
    if ((int) $pdo->query('SELECT count(*) FROM fizzbuzz_request_stat s WHERE s.hits != (SELECT count(*) FROM fizzbuzz_request_log l WHERE l.stat_id = s.id)')->fetchColumn() > 0) {
        $violations[] = 'hits différent du nombre de références';
    }
    $logIds = $pdo->query('SELECT count(*) AS c, coalesce(max(id) - min(id) + 1, 0) AS span FROM fizzbuzz_request_log')->fetch(PDO::FETCH_ASSOC);
    if ((int) $logIds['c'] !== (int) $logIds['span']) {
        $violations[] = 'identifiants du journal non contigus';
    }

    return $violations;
}
