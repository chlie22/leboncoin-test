<?php

declare(strict_types=1);

namespace App\FizzBuzz\Application;

use App\FizzBuzz\Application\Exception\StatisticsStoreUnavailable;
use App\FizzBuzz\Application\Model\RequestStatistics;
use App\FizzBuzz\Application\Port\RequestStatisticsStore;

/**
 * Reads the most frequent request of the sliding window. An unavailable store propagates to the caller.
 */
final readonly class GetMostFrequentRequest
{
    public function __construct(private RequestStatisticsStore $statistics)
    {
    }

    /**
     * @throws StatisticsStoreUnavailable
     */
    public function execute(): RequestStatistics
    {
        return $this->statistics->findMostFrequent();
    }
}
