<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\FizzBuzz\Application\Model\RequestStatistics;
use App\FizzBuzz\Application\Port\RequestStatisticsStore;
use App\FizzBuzz\Domain\FizzBuzzParameters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The RequestStatisticsStore contract (docs/conception.md §3.4, §5.4, §6.3), written once and run by every adapter.
 *
 * Failure behaviour is adapter-specific (in-memory "down" mode, SQLite lock or read-only file) and stays out of here.
 */
abstract class RequestStatisticsStoreContractTest extends TestCase
{
    abstract protected function createStore(int $windowSize): RequestStatisticsStore;

    public function testAnEmptyWindowHasNoRequestAndReportsItsSize(): void
    {
        $statistics = $this->createStore(5)->findMostFrequent();

        self::assertNull($statistics->request);
        self::assertSame(0, $statistics->hits);
        self::assertSame(5, $statistics->windowSize);
        self::assertSame(0, $statistics->windowCount);
    }

    public function testReturnsTheRecordedValuesWithTheirTypes(): void
    {
        $store = $this->createStore(5);
        $recorded = new FizzBuzzParameters(3, 5, 10_000, '0', "fi\u{1F600}zz ");

        $store->record($recorded);

        $this->assertStatistics($store->findMostFrequent(), $recorded, hits: 1, windowSize: 5, windowCount: 1);
    }

    public function testCountsOccurrencesOfTheSameCombination(): void
    {
        $store = $this->createStore(5);
        $a = self::a();
        $b = self::b();

        $store->record($a);
        $store->record($a);
        $store->record($b);

        $this->assertStatistics($store->findMostFrequent(), $a, hits: 2, windowSize: 5, windowCount: 3);
    }

    public function testReadingDoesNotCount(): void
    {
        $store = $this->createStore(5);
        $store->record(self::a());

        $store->findMostFrequent();
        $store->findMostFrequent();

        $this->assertStatistics($store->findMostFrequent(), self::a(), hits: 1, windowSize: 5, windowCount: 1);
    }

    /**
     * §3.4: with N = 3, A, A, B then C evicts the first A; three counters at 1, and A is the oldest.
     */
    public function testTheOldestCallLeavesTheWindow(): void
    {
        $store = $this->createStore(3);

        $store->record(self::a());
        $store->record(self::a());
        $store->record(self::b());
        $store->record(self::c());

        $this->assertStatistics($store->findMostFrequent(), self::a(), hits: 1, windowSize: 3, windowCount: 3);
    }

    /**
     * §3.4: with N = 4, A, B, B, A gives A = 2 and B = 2; A has been in the window the longest.
     */
    public function testATieReturnsTheCombinationPresentForTheLongest(): void
    {
        $store = $this->createStore(4);

        $store->record(self::a());
        $store->record(self::b());
        $store->record(self::b());
        $store->record(self::a());

        $this->assertStatistics($store->findMostFrequent(), self::a(), hits: 2, windowSize: 4, windowCount: 4);
    }

    /**
     * §3.4: a combination that left the window entirely and came back is new, so it loses the tie.
     * N = 2: A, B, C evicts A entirely; A again evicts B; window C, A with one hit each; C is older.
     */
    public function testACombinationThatLeftAndCameBackIsNew(): void
    {
        $store = $this->createStore(2);

        $store->record(self::a());
        $store->record(self::b());
        $store->record(self::c());
        $store->record(self::a());

        $this->assertStatistics($store->findMostFrequent(), self::c(), hits: 1, windowSize: 2, windowCount: 2);
    }

    /**
     * The former winner declines: N = 3, A, A, B, B keeps A, B, B.
     */
    public function testAFormerWinnerDeclines(): void
    {
        $store = $this->createStore(3);

        $store->record(self::a());
        $store->record(self::a());
        self::assertSame(2, $store->findMostFrequent()->hits);

        $store->record(self::b());
        $store->record(self::b());

        $this->assertStatistics($store->findMostFrequent(), self::b(), hits: 2, windowSize: 3, windowCount: 3);
    }

    public function testAWindowOfOneKeepsOnlyTheLastCall(): void
    {
        $store = $this->createStore(1);

        $store->record(self::a());
        $this->assertStatistics($store->findMostFrequent(), self::a(), hits: 1, windowSize: 1, windowCount: 1);

        $store->record(self::b());
        $this->assertStatistics($store->findMostFrequent(), self::b(), hits: 1, windowSize: 1, windowCount: 1);
    }

    public function testAWindowOfOneRecordingTheSameCombinationTwiceKeepsOneHit(): void
    {
        $store = $this->createStore(1);

        $store->record(self::a());
        $store->record(self::a());

        $this->assertStatistics($store->findMostFrequent(), self::a(), hits: 1, windowSize: 1, windowCount: 1);
    }

    /**
     * §6.3: the increment happens before the eviction. N = 2, A, B, A: the first A leaves while the second enters,
     * so A never leaves the window, keeps its identity and wins the tie. Evicting first would make A new, and B would win.
     */
    public function testACombinationEnteringWhileItsOldestCallLeavesKeepsItsSeniority(): void
    {
        $store = $this->createStore(2);

        $store->record(self::a());
        $store->record(self::b());
        $store->record(self::a());

        $this->assertStatistics($store->findMostFrequent(), self::a(), hits: 1, windowSize: 2, windowCount: 2);
    }

    /**
     * Boundary N / N + 1 with N = 5: the fifth call fills the window, the sixth evicts exactly one.
     */
    public function testTheCallAfterAFullWindowEvictsExactlyOne(): void
    {
        $store = $this->createStore(5);

        for ($call = 1; $call <= 5; ++$call) {
            $store->record(self::a());
        }
        $this->assertStatistics($store->findMostFrequent(), self::a(), hits: 5, windowSize: 5, windowCount: 5);

        $store->record(self::b());
        $this->assertStatistics($store->findMostFrequent(), self::a(), hits: 4, windowSize: 5, windowCount: 5);
    }

    public function testTheWindowCountNeverExceedsTheWindowSize(): void
    {
        $store = $this->createStore(3);
        $calls = [self::a(), self::b(), self::a(), self::c(), self::c(), self::b(), self::a(), self::a(), self::c(), self::b()];

        foreach ($calls as $index => $parameters) {
            $store->record($parameters);
            $statistics = $store->findMostFrequent();

            self::assertSame(min($index + 1, 3), $statistics->windowCount);
            self::assertSame(3, $statistics->windowSize);
            self::assertGreaterThanOrEqual(1, $statistics->hits);
            self::assertLessThanOrEqual($statistics->windowCount, $statistics->hits);
        }
    }

    /**
     * §3.4: the identity is the typed quintuple; strings are compared byte by byte.
     * The first combination is recorded once, the second twice: a store merging them would return the first with 3 hits.
     */
    #[DataProvider('distinctCombinations')]
    public function testCombinationsAreComparedStrictly(FizzBuzzParameters $once, FizzBuzzParameters $twice): void
    {
        $store = $this->createStore(5);

        $store->record($once);
        $store->record($twice);
        $store->record($twice);

        $this->assertStatistics($store->findMostFrequent(), $twice, hits: 2, windowSize: 5, windowCount: 3);
    }

    /**
     * @return iterable<string, array{FizzBuzzParameters, FizzBuzzParameters}>
     */
    public static function distinctCombinations(): iterable
    {
        yield '"00" and "0"' => [new FizzBuzzParameters(3, 5, 15, '00', 'buzz'), new FizzBuzzParameters(3, 5, 15, '0', 'buzz')];
        yield 'case' => [new FizzBuzzParameters(3, 5, 15, 'Fizz', 'buzz'), new FizzBuzzParameters(3, 5, 15, 'fizz', 'buzz')];
        yield 'leading space' => [new FizzBuzzParameters(3, 5, 15, ' fizz', 'buzz'), new FizzBuzzParameters(3, 5, 15, 'fizz', 'buzz')];
        yield 'strings swapped' => [new FizzBuzzParameters(3, 5, 15, 'buzz', 'fizz'), new FizzBuzzParameters(3, 5, 15, 'fizz', 'buzz')];
        yield 'integers swapped' => [new FizzBuzzParameters(5, 3, 15, 'fizz', 'buzz'), new FizzBuzzParameters(3, 5, 15, 'fizz', 'buzz')];
        yield 'limit' => [new FizzBuzzParameters(3, 5, 16, 'fizz', 'buzz'), new FizzBuzzParameters(3, 5, 15, 'fizz', 'buzz')];
        yield 'unicode normalisation' => [new FizzBuzzParameters(3, 5, 15, "e\u{0301}", 'buzz'), new FizzBuzzParameters(3, 5, 15, "\u{00E9}", 'buzz')];
    }

    /**
     * Property by property with assertSame: FizzBuzzParameters must never be compared loosely ('0' == '00').
     */
    final protected function assertStatistics(RequestStatistics $statistics, FizzBuzzParameters $request, int $hits, int $windowSize, int $windowCount): void
    {
        self::assertNotNull($statistics->request);
        self::assertSame($request->int1, $statistics->request->int1);
        self::assertSame($request->int2, $statistics->request->int2);
        self::assertSame($request->limit, $statistics->request->limit);
        self::assertSame($request->str1, $statistics->request->str1);
        self::assertSame($request->str2, $statistics->request->str2);
        self::assertSame($hits, $statistics->hits);
        self::assertSame($windowSize, $statistics->windowSize);
        self::assertSame($windowCount, $statistics->windowCount);
    }

    private static function a(): FizzBuzzParameters
    {
        return new FizzBuzzParameters(3, 5, 15, 'fizz', 'buzz');
    }

    private static function b(): FizzBuzzParameters
    {
        return new FizzBuzzParameters(2, 7, 30, 'foo', 'bar');
    }

    private static function c(): FizzBuzzParameters
    {
        return new FizzBuzzParameters(4, 6, 12, 'x', 'y');
    }
}
