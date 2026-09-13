<?php

declare(strict_types=1);

namespace App\FizzBuzz\Application\Port;

use App\FizzBuzz\Application\Exception\StatisticsStoreUnavailable;
use App\FizzBuzz\Application\Model\RequestStatistics;
use App\FizzBuzz\Domain\FizzBuzzParameters;

interface RequestStatisticsStore
{
    /** @throws StatisticsStoreUnavailable */
    public function record(FizzBuzzParameters $parameters): void;

    /** @throws StatisticsStoreUnavailable */
    public function findMostFrequent(): RequestStatistics;
}
