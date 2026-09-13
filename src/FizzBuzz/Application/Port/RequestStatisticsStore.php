<?php

declare(strict_types=1);

namespace App\FizzBuzz\Application\Port;

use App\FizzBuzz\Application\Exception\StatisticsStoreUnavailable;
use App\FizzBuzz\Application\Model\RequestStatistics;
use App\FizzBuzz\Domain\FizzBuzzParameters;

/**
 * Sliding window of the last recorded FizzBuzz requests.
 *
 * Contract (docs/conception.md §5.4), run by every adapter through RequestStatisticsStoreContractTest.
 */
interface RequestStatisticsStore
{
    /**
     * Records one call atomically: add, increment, evict and decrement succeed together or nothing changes.
     *
     * @throws StatisticsStoreUnavailable
     */
    public function record(FizzBuzzParameters $parameters): void;

    /**
     * Most frequent combination of the window, the oldest one on a tie, with the window metadata of the same state.
     *
     * @throws StatisticsStoreUnavailable
     */
    public function findMostFrequent(): RequestStatistics;
}
