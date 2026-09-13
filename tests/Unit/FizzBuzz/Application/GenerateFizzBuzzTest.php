<?php

declare(strict_types=1);

namespace App\Tests\Unit\FizzBuzz\Application;

use App\FizzBuzz\Application\Exception\StatisticsStoreUnavailable;
use App\FizzBuzz\Application\GenerateFizzBuzz;
use App\FizzBuzz\Domain\FizzBuzzGenerator;
use App\FizzBuzz\Domain\FizzBuzzParameters;
use App\Tests\Support\InMemoryRequestStatisticsStore;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

#[CoversClass(GenerateFizzBuzz::class)]
final class GenerateFizzBuzzTest extends TestCase
{
    private InMemoryRequestStatisticsStore $store;
    private RecordingLogger $logger;
    private GenerateFizzBuzz $useCase;

    protected function setUp(): void
    {
        $this->store = new InMemoryRequestStatisticsStore(5);
        $this->logger = new RecordingLogger();
        $this->useCase = new GenerateFizzBuzz(new FizzBuzzGenerator(), $this->store, $this->logger);
    }

    public function testReturnsTheGeneratedSequenceIntact(): void
    {
        $sequence = $this->useCase->execute(new FizzBuzzParameters(3, 5, 15, 'fizz', 'buzz'));

        self::assertSame(['1', '2', 'fizz', '4', 'buzz', 'fizz', '7', '8', 'fizz', 'buzz', '11', 'fizz', '13', '14', 'fizzbuzz'], $sequence);
    }

    public function testRecordsTheCallExactlyOnce(): void
    {
        $this->useCase->execute(new FizzBuzzParameters(3, 5, 15, '0', 'buzz'));

        self::assertSame(1, $this->store->recordAttempts());
        $statistics = $this->store->findMostFrequent();
        self::assertNotNull($statistics->request);
        self::assertSame('0', $statistics->request->str1);
        self::assertSame(1, $statistics->hits);
        self::assertSame(1, $statistics->windowCount);
        self::assertSame([], $this->logger->records);
    }

    public function testStillReturnsTheSequenceWhenTheStoreIsDown(): void
    {
        $this->store->breakDown();

        $sequence = $this->useCase->execute(new FizzBuzzParameters(2, 3, 6, 'fizz', 'buzz'));

        self::assertSame(['1', 'fizz', 'buzz', 'fizz', '5', 'fizzbuzz'], $sequence);
    }

    public function testLogsASkippedRecordWithClassNamesOnlyAndDoesNotRetry(): void
    {
        $this->store->breakDown();

        $this->useCase->execute(new FizzBuzzParameters(3, 5, 15, 'marker-str1', 'marker-str2'));

        self::assertSame(1, $this->store->recordAttempts());
        self::assertSame([[
            'level' => LogLevel::WARNING,
            'message' => 'statistics.record_skipped',
            'context' => ['error_class' => StatisticsStoreUnavailable::class, 'cause_class' => \RuntimeException::class],
        ]], $this->logger->records);
        self::assertStringNotContainsString('marker', var_export($this->logger->records, true));
    }

    public function testACallServedInDegradedModeIsNotCounted(): void
    {
        $this->store->breakDown();
        $this->useCase->execute(new FizzBuzzParameters(3, 5, 15, 'fizz', 'buzz'));
        $this->store->repair();

        self::assertSame(0, $this->store->findMostFrequent()->windowCount);
    }

    /**
     * §5.4: a programming error stays unexpected. Only StatisticsStoreUnavailable switches to degraded mode.
     */
    public function testLetsAnUnexpectedStoreErrorPropagateWithoutLogging(): void
    {
        $this->store->breakDown(new \LogicException('Programming error.'));

        try {
            $this->useCase->execute(new FizzBuzzParameters(3, 5, 15, 'fizz', 'buzz'));
            self::fail('An unexpected store error must propagate.');
        } catch (\LogicException $error) {
            self::assertSame('Programming error.', $error->getMessage());
        }

        self::assertSame([], $this->logger->records);
    }

    public function testLogsANullCauseWhenTheFailureHasNone(): void
    {
        $this->store->breakDown(new StatisticsStoreUnavailable());

        $this->useCase->execute(new FizzBuzzParameters(3, 5, 15, 'fizz', 'buzz'));

        self::assertCount(1, $this->logger->records);
        self::assertSame(['error_class' => StatisticsStoreUnavailable::class, 'cause_class' => null], $this->logger->records[0]['context']);
    }
}
