<?php

declare(strict_types=1);

namespace App\FizzBuzz\Application\Model;

use App\FizzBuzz\Domain\FizzBuzzParameters;

/**
 * Most frequent request among the last calls, and the window it was read from, taken from one storage state.
 */
final readonly class RequestStatistics
{
    /**
     * @param FizzBuzzParameters|null $request     null when the window is empty
     * @param int                     $hits        occurrences of $request in the window, 0 when empty
     * @param int                     $windowSize  configured number of calls kept
     * @param int                     $windowCount calls currently kept, never above $windowSize
     */
    public function __construct(
        public ?FizzBuzzParameters $request,
        public int $hits,
        public int $windowSize,
        public int $windowCount,
    ) {
    }
}
