<?php

declare(strict_types=1);

namespace App\Tests\Unit\FizzBuzz\Application;

use App\FizzBuzz\Application\Exception\StatisticsStoreUnavailable;
use App\FizzBuzz\Application\GetMostFrequentRequest;
use App\FizzBuzz\Domain\FizzBuzzParameters;
use App\Tests\Support\InMemoryRequestStatisticsStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetMostFrequentRequest::class)]
final class GetMostFrequentRequestTest extends TestCase
{
    public function testReturnsTheMostFrequentRequestOfTheWindow(): void
    {
        $store = new InMemoryRequestStatisticsStore(5);
        $store->record(new FizzBuzzParameters(3, 5, 15, 'fizz', 'buzz'));
        $store->record(new FizzBuzzParameters(3, 5, 15, 'fizz', 'buzz'));
        $store->record(new FizzBuzzParameters(2, 7, 30, 'foo', 'bar'));

        $statistics = (new GetMostFrequentRequest($store))->execute();

        self::assertNotNull($statistics->request);
        self::assertSame('fizz', $statistics->request->str1);
        self::assertSame(15, $statistics->request->limit);
        self::assertSame(2, $statistics->hits);
        self::assertSame(5, $statistics->windowSize);
        self::assertSame(3, $statistics->windowCount);
    }

    public function testReturnsAnEmptyWindow(): void
    {
        $statistics = (new GetMostFrequentRequest(new InMemoryRequestStatisticsStore(5)))->execute();

        self::assertNull($statistics->request);
        self::assertSame(0, $statistics->hits);
        self::assertSame(5, $statistics->windowSize);
        self::assertSame(0, $statistics->windowCount);
    }

    public function testReadingDoesNotRecord(): void
    {
        $store = new InMemoryRequestStatisticsStore(5);
        $useCase = new GetMostFrequentRequest($store);

        $useCase->execute();
        $statistics = $useCase->execute();

        self::assertSame(0, $store->recordAttempts());
        self::assertSame(0, $statistics->windowCount);
    }

    public function testPropagatesAnUnavailableStore(): void
    {
        $store = new InMemoryRequestStatisticsStore(5);
        $store->breakDown();

        $this->expectException(StatisticsStoreUnavailable::class);

        (new GetMostFrequentRequest($store))->execute();
    }
}
