<?php

declare(strict_types=1);

namespace App\FizzBuzz\Application;

use App\FizzBuzz\Application\Exception\StatisticsStoreUnavailable;
use App\FizzBuzz\Application\Port\RequestStatisticsStore;
use App\FizzBuzz\Domain\FizzBuzzGenerator;
use App\FizzBuzz\Domain\FizzBuzzParameters;
use Psr\Log\LoggerInterface;

/**
 * Generates the sequence, then records the call once.
 *
 * Degraded mode (docs/conception.md §5.10): statistics are secondary, so an unavailable store is logged and the
 * sequence is still returned. No retry. Only class names are logged, never the parameters nor a storage message.
 */
final readonly class GenerateFizzBuzz
{
    public function __construct(
        private FizzBuzzGenerator $generator,
        private RequestStatisticsStore $statistics,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<string>
     */
    public function execute(FizzBuzzParameters $parameters): array
    {
        $sequence = $this->generator->generate($parameters);

        try {
            $this->statistics->record($parameters);
        } catch (StatisticsStoreUnavailable $unavailable) {
            $cause = $unavailable->getPrevious();

            $this->logger->warning('statistics.record_skipped', [
                'error_class' => $unavailable::class,
                'cause_class' => null === $cause ? null : $cause::class,
            ]);
        }

        return $sequence;
    }
}
