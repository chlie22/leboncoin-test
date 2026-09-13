<?php

declare(strict_types=1);

namespace App\Tests\Integration\FizzBuzz\Persistence;

use App\FizzBuzz\Infrastructure\Persistence\SqliteRequestStatisticsStore;
use App\Tests\Support\SqliteTestDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Several PHP processes record and read in parallel on one shared file (docs/conception.md §9.1).
 *
 * The file is prepared by the migrations in a subprocess, never copied from var/test.db, which is open in WAL mode.
 */
#[CoversClass(SqliteRequestStatisticsStore::class)]
final class SqliteRequestStatisticsStoreConcurrencyTest extends TestCase
{
    private const int WRITERS = 4;
    private const int CALLS_PER_WRITER = 100;

    private string $path;

    /** @var list<resource> */
    private array $processes = [];

    protected function setUp(): void
    {
        $this->path = SqliteTestDatabase::temporaryPath();
        $migration = SqliteTestDatabase::migrate($this->path);
        self::assertSame(0, $migration['exitCode'], $migration['output']);
    }

    protected function tearDown(): void
    {
        // A process closed by finish() is no longer a resource; one left running by a failed assertion is stopped.
        foreach ($this->processes as $process) {
            if (\is_resource($process) && proc_get_status($process)['running']) {
                proc_terminate($process);
            }
        }
        SqliteTestDatabase::remove($this->path);
    }

    /**
     * N = 1 000 is above the 400 calls: nothing is evicted, so every combination has exactly its confirmed calls.
     */
    public function testConcurrentWritersLoseNoCallAndDuplicateNone(): void
    {
        $confirmed = $this->recordConcurrently(1_000);

        $connection = SqliteTestDatabase::connectionTo($this->path);
        try {
            $hits = [];
            foreach ($connection->fetchAllAssociative('SELECT str1, hits FROM fizzbuzz_request_stat') as $row) {
                self::assertIsString($row['str1']);
                $hits[$row['str1']] = $row['hits'];
            }
            ksort($hits);
            ksort($confirmed);

            self::assertSame($confirmed, $hits);
            self::assertSame(array_sum($confirmed), $connection->fetchOne('SELECT count(*) FROM fizzbuzz_request_log'));
            self::assertSame([], SqliteTestDatabase::invariantViolations($connection, 1_000));
        } finally {
            $connection->close();
        }
    }

    /**
     * N = 50: the writers evict under contention.
     */
    public function testConcurrentWritersKeepTheLastCallsOfTheWindow(): void
    {
        $confirmed = $this->recordConcurrently(50);

        $connection = SqliteTestDatabase::connectionTo($this->path);
        try {
            self::assertSame(min(array_sum($confirmed), 50), $connection->fetchOne('SELECT count(*) FROM fizzbuzz_request_log'));
            self::assertSame([], SqliteTestDatabase::invariantViolations($connection, 50));
        } finally {
            $connection->close();
        }
    }

    /**
     * Starts the writers and one reader, waits until all are connected, then releases them together.
     *
     * @return array<string, int> confirmed calls by combination (str1), over all writers
     */
    private function recordConcurrently(int $windowSize): array
    {
        $reader = $this->start($windowSize, 'read', 0);
        $writers = [];
        for ($worker = 1; $worker <= self::WRITERS; ++$worker) {
            $writers[$worker] = $this->start($windowSize, 'write', $worker);
        }
        foreach ([$reader, ...$writers] as $started) {
            self::assertSame("ready\n", fgets($started['stdout']), self::stderrOf($started));
        }
        foreach ([$reader, ...$writers] as $started) {
            fwrite($started['stdin'], "go\n");
        }

        $confirmed = [];
        foreach ($writers as $worker => $writer) {
            $result = $this->finish($writer);
            self::assertIsArray($result['successes']);
            self::assertGreaterThanOrEqual(1, array_sum($result['successes']), \sprintf('Writer %d confirmed no call: the test would prove nothing.', $worker));
            foreach ($result['successes'] as $combination => $successes) {
                self::assertIsInt($successes);
                $confirmed[(string) $combination] = ($confirmed[(string) $combination] ?? 0) + $successes;
            }
        }

        fwrite($reader['stdin'], "stop\n");
        $read = $this->finish($reader);
        self::assertIsInt($read['reads']);
        self::assertGreaterThanOrEqual(1, $read['reads']);
        self::assertSame([], $read['violations']);

        return $confirmed;
    }

    /**
     * @return array{process: resource, stdin: resource, stdout: resource, stderr: resource}
     */
    private function start(int $windowSize, string $role, int $worker): array
    {
        $stderr = tmpfile();
        \assert(false !== $stderr);
        $process = proc_open(
            [\PHP_BINARY, __DIR__.'/Fixtures/record-worker.php', $this->path, (string) $windowSize, $role, (string) $worker, (string) self::CALLS_PER_WRITER],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $stderr],
            $pipes,
        );
        \assert(\is_resource($process));
        $this->processes[] = $process;

        return ['process' => $process, 'stdin' => $pipes[0], 'stdout' => $pipes[1], 'stderr' => $stderr];
    }

    /**
     * @param array{process: resource, stdin: resource, stdout: resource, stderr: resource} $started
     *
     * @return array<mixed>
     */
    private function finish(array $started): array
    {
        $output = (string) stream_get_contents($started['stdout']);
        fclose($started['stdin']);
        fclose($started['stdout']);
        $exitCode = proc_close($started['process']);
        $stderr = self::stderrOf($started);

        self::assertSame(0, $exitCode, $stderr.$output);
        self::assertSame('', $stderr);
        $result = json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);

        return $result;
    }

    /**
     * @param array{process: resource, stdin: resource, stdout: resource, stderr: resource} $started
     */
    private static function stderrOf(array $started): string
    {
        rewind($started['stderr']);

        return (string) stream_get_contents($started['stderr']);
    }
}
