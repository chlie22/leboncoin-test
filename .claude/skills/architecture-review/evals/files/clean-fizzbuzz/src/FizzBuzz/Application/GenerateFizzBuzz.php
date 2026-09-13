<?php

declare(strict_types=1);

namespace App\FizzBuzz\Application;

use App\FizzBuzz\Application\Exception\StatisticsStoreUnavailable;
use App\FizzBuzz\Application\Port\RequestStatisticsStore;
use App\FizzBuzz\Domain\FizzBuzzGenerator;
use App\FizzBuzz\Domain\FizzBuzzParameters;
use Psr\Log\LoggerInterface;

final class GenerateFizzBuzz
{
    public function __construct(
        private readonly FizzBuzzGenerator $generator,
        private readonly RequestStatisticsStore $statistics,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return list<string> */
    public function execute(FizzBuzzParameters $parameters): array
    {
        $sequence = $this->generator->generate($parameters);

        try {
            $this->statistics->record($parameters);
        } catch (StatisticsStoreUnavailable $exception) {
            $this->logger->warning('statistics.record_skipped', ['error' => $exception::class]);
        }

        return $sequence;
    }
}
