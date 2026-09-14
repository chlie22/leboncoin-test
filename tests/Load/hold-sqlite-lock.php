<?php

declare(strict_types=1);

/**
 * Holds an exclusive SQLite write lock (BEGIN IMMEDIATE) for CONTENTION_SECONDS.
 * Used by the load-test contention scenario to exercise real WAL locking (§5.10, §9.4).
 *
 * Env:
 *   DATABASE_PATH     absolute path to the SQLite file (default /app/var/data/app.db)
 *   CONTENTION_SECONDS how long to hold the lock (default 55)
 */
$path = getenv('DATABASE_PATH') ?: '/app/var/data/app.db';
$seconds = (int) (getenv('CONTENTION_SECONDS') ?: '55');
if ($seconds < 1) {
    fwrite(STDERR, "CONTENTION_SECONDS must be >= 1\n");
    exit(1);
}

$pdo = new PDO('sqlite:'.$path, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
// Do not set a short busy_timeout here: this process is the lock holder.
$pdo->exec('PRAGMA busy_timeout = 0');
$pdo->exec('BEGIN IMMEDIATE');
fwrite(STDOUT, sprintf("sqlite write lock held for %d s on %s\n", $seconds, $path));
fflush(STDOUT);
sleep($seconds);
$pdo->exec('COMMIT');
fwrite(STDOUT, "sqlite write lock released\n");
