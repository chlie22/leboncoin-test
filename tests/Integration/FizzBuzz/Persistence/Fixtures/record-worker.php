<?php

declare(strict_types=1);

/*
 * Process of SqliteRequestStatisticsStoreConcurrencyTest: one connection, one adapter, as a PHP-FPM worker would have.
 *
 * Arguments: database path, window size, role ("write" or "read"), worker number, calls per writer.
 * Protocol: prints "ready" once connected, then waits for "go" on stdin so that the workers overlap;
 * a writer records its calls, a reader reads until "stop". Prints one JSON line with its results.
 * PHP errors go to stderr, which the test requires to be empty.
 */

use App\FizzBuzz\Application\Exception\StatisticsStoreUnavailable;
use App\FizzBuzz\Domain\FizzBuzzParameters;
use App\FizzBuzz\Infrastructure\Persistence\SqliteRequestStatisticsStore;
use App\Tests\Support\SqliteTestDatabase;

ini_set('display_errors', 'stderr');
error_reporting(\E_ALL);

require dirname(__DIR__, 5).'/vendor/autoload.php';

$arguments = [];
foreach (is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [] as $argument) {
    if (is_string($argument)) {
        $arguments[] = $argument;
    }
}
if (6 !== count($arguments)) {
    fwrite(\STDERR, "Usage: record-worker.php <database> <window size> <write|read> <worker> <calls>\n");
    exit(2);
}
[, $path, $windowSize, $role, $worker, $calls] = $arguments;
$windowSize = (int) $windowSize;

$store = new SqliteRequestStatisticsStore(SqliteTestDatabase::connectionTo($path), $windowSize);
$store->findMostFrequent();
fwrite(\STDOUT, "ready\n");

if ("go\n" !== fgets(\STDIN)) {
    fwrite(\STDERR, "Expected \"go\" on stdin.\n");
    exit(2);
}

$unavailable = 0;

if ('write' === $role) {
    // Keyed by str1, unique per combination.
    $combinations = [
        'fizz' => new FizzBuzzParameters(3, 5, 15, 'fizz', 'buzz'),
        'foo' => new FizzBuzzParameters(2, 7, 30, 'foo', 'bar'),
        'x' => new FizzBuzzParameters(4, 6, 12, 'x', 'y'),
    ];
    $keys = array_keys($combinations);
    $successes = array_fill_keys($keys, 0);

    for ($call = 0; $call < (int) $calls; ++$call) {
        $key = $keys[($call + (int) $worker) % count($keys)];
        try {
            $store->record($combinations[$key]);
            ++$successes[$key];
        } catch (StatisticsStoreUnavailable) {
            ++$unavailable;
        }
    }

    echo json_encode(['successes' => $successes, 'unavailable' => $unavailable], \JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

// Reader: every read comes from one state of the database (one statement, WAL snapshot).
stream_set_blocking(\STDIN, false);
$reads = 0;
$violations = [];

while (true) {
    try {
        $statistics = $store->findMostFrequent();
        ++$reads;
        if ($statistics->windowSize !== $windowSize
            || $statistics->windowCount > $windowSize
            || $statistics->hits > $statistics->windowCount
            || ($statistics->windowCount > 0) !== (null !== $statistics->request && $statistics->hits >= 1)) {
            $violations[] = sprintf('hits %d, window count %d, window size %d', $statistics->hits, $statistics->windowCount, $statistics->windowSize);
        }
    } catch (StatisticsStoreUnavailable) {
        ++$unavailable;
    }

    $line = fgets(\STDIN);
    if ("stop\n" === $line || feof(\STDIN)) {
        break;
    }
    usleep(1_000);
}

echo json_encode(['reads' => $reads, 'violations' => array_slice($violations, 0, 10), 'unavailable' => $unavailable], \JSON_THROW_ON_ERROR), "\n";
