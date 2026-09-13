<?php

declare(strict_types=1);

namespace App\FizzBuzz\Application\Exception;

/**
 * Expected unavailability of the statistics storage (failure, lock wait timeout).
 *
 * The message is fixed: the storage error, which may embed SQL values, only travels as the previous exception.
 */
final class StatisticsStoreUnavailable extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('The statistics store is unavailable.', 0, $previous);
    }
}
