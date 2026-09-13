<?php

declare(strict_types=1);

namespace App\FizzBuzz\Application\Model;

use App\FizzBuzz\Domain\FizzBuzzParameters;

final readonly class RequestStatistics
{
    public function __construct(
        public ?FizzBuzzParameters $request,
        public int $hits,
        public int $windowSize,
        public int $windowCount,
    ) {
    }
}
