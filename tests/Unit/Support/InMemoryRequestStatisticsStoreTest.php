<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\FizzBuzz\Application\Exception\StatisticsStoreUnavailable;
use App\FizzBuzz\Application\Model\RequestStatistics;
use App\FizzBuzz\Application\Port\RequestStatisticsStore;
use App\FizzBuzz\Domain\FizzBuzzParameters;
use App\Tests\Contract\RequestStatisticsStoreContractTest;
use App\Tests\Support\InMemoryRequestStatisticsStore;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RequestStatistics::class)]
#[CoversClass(StatisticsStoreUnavailable::class)]
final class InMemoryRequestStatisticsStoreTest extends RequestStatisticsStoreContractTest
{
    protected function createStore(int $windowSize): RequestStatisticsStore
    {
        return new InMemoryRequestStatisticsStore($windowSize);
    }

    public function testRejectsAWindowSmallerThanOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new InMemoryRequestStatisticsStore(0);
    }

    public function testRecordFailsWhenDownAndChangesNothing(): void
    {
        $store = new InMemoryRequestStatisticsStore(5);
        $store->record(new FizzBuzzParameters(3, 5, 15, 'fizz', 'buzz'));
        $store->breakDown();

        try {
            $store->record(new FizzBuzzParameters(2, 7, 30, 'foo', 'bar'));
            self::fail('record() must throw while the store is down.');
        } catch (StatisticsStoreUnavailable $unavailable) {
            self::assertInstanceOf(\RuntimeException::class, $unavailable->getPrevious());
        }

        $store->repair();
        $this->assertStatistics($store->findMostFrequent(), new FizzBuzzParameters(3, 5, 15, 'fizz', 'buzz'), hits: 1, windowSize: 5, windowCount: 1);
    }

    public function testFindMostFrequentFailsWhenDown(): void
    {
        $store = new InMemoryRequestStatisticsStore(5);
        $store->breakDown();

        $this->expectException(StatisticsStoreUnavailable::class);

        $store->findMostFrequent();
    }

    public function testCountsRecordAttemptsIncludingFailedOnes(): void
    {
        $store = new InMemoryRequestStatisticsStore(5);
        $parameters = new FizzBuzzParameters(3, 5, 15, 'fizz', 'buzz');

        $store->record($parameters);
        $store->breakDown();
        try {
            $store->record($parameters);
        } catch (StatisticsStoreUnavailable) {
        }

        self::assertSame(2, $store->recordAttempts());
    }
}
