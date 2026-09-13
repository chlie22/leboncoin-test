<?php

declare(strict_types=1);

namespace App\Tests\Integration\FizzBuzz\Cli;

use App\FizzBuzz\Domain\FizzBuzzParameters;
use App\FizzBuzz\Infrastructure\Cli\ApplyStatisticsWindowCommand;
use App\FizzBuzz\Infrastructure\Persistence\SqliteRequestStatisticsStore;
use App\Tests\Support\SqliteTestDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Startup command applying STATS_WINDOW_SIZE (docs/conception.md §6.6, R09).
 */
#[CoversClass(ApplyStatisticsWindowCommand::class)]
final class ApplyStatisticsWindowCommandTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqliteTestDatabase::kernelConnection();
        SqliteTestDatabase::clear($this->connection);
    }

    public function testReducingTheWindowIsVisibleToTheNextReadWithoutAnyCall(): void
    {
        $this->record(10, 'AAAAAABBBB');
        $reduced = new SqliteRequestStatisticsStore($this->connection, 3);

        $tester = new CommandTester(new ApplyStatisticsWindowCommand($reduced));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('3', $tester->getDisplay());
        $statistics = $reduced->findMostFrequent();
        self::assertSame(3, $statistics->windowCount);
        self::assertSame(3, $statistics->windowSize);
        self::assertSame(3, $statistics->hits);
        self::assertNotNull($statistics->request);
        self::assertSame('foo', $statistics->request->str1);
        self::assertSame([], SqliteTestDatabase::invariantViolations($this->connection, 3));
    }

    /**
     * @param string $calls recorded with a window of 3 before the command
     */
    #[DataProvider('windowsWithNothingToEvict')]
    public function testNothingToEvictChangesNothing(string $calls): void
    {
        $this->record(3, $calls);
        $before = SqliteTestDatabase::snapshot($this->connection);

        $tester = new CommandTester(new ApplyStatisticsWindowCommand(new SqliteRequestStatisticsStore($this->connection, 3)));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame($before, SqliteTestDatabase::snapshot($this->connection));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function windowsWithNothingToEvict(): iterable
    {
        yield 'empty window' => [''];
        yield 'window not full' => ['AB'];
        yield 'full window' => ['ABA'];
    }

    public function testTheCommandIsRegisteredWithTheConfiguredWindowSize(): void
    {
        $this->record(10, 'AAAAAABBBB');
        $command = (new Application(SqliteTestDatabase::kernel()))->find('app:statistics:apply-window');

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(5, (new SqliteRequestStatisticsStore($this->connection, 5))->findMostFrequent()->windowCount, 'STATS_WINDOW_SIZE of .env.test');
        self::assertSame([], SqliteTestDatabase::invariantViolations($this->connection, 5));
    }

    #[DataProvider('invalidWindowSizes')]
    public function testAnInvalidWindowSizeFailsTheCommandAndChangesNothing(string $windowSize): void
    {
        $this->record(10, 'AAAB');
        $before = SqliteTestDatabase::snapshot($this->connection);

        $result = SqliteTestDatabase::console(['app:statistics:apply-window'], ['STATS_WINDOW_SIZE' => $windowSize]);

        self::assertNotSame(0, $result['exitCode'], $result['output']);
        self::assertStringContainsString('STATS_WINDOW_SIZE must be a positive integer written in decimal', $result['output']);
        self::assertSame($before, SqliteTestDatabase::snapshot($this->connection));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidWindowSizes(): iterable
    {
        yield '0' => ['0'];
        yield '1.5' => ['1.5'];
        yield 'abc' => ['abc'];
    }

    private function record(int $windowSize, string $calls): void
    {
        $store = new SqliteRequestStatisticsStore($this->connection, $windowSize);
        foreach ('' === $calls ? [] : str_split($calls) as $key) {
            $store->record('A' === $key ? new FizzBuzzParameters(3, 5, 15, 'fizz', 'buzz') : new FizzBuzzParameters(2, 7, 30, 'foo', 'bar'));
        }
    }
}
