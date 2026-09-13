<?php

declare(strict_types=1);

namespace App\FizzBuzz\Domain;

use App\FizzBuzz\Domain\Exception\InvalidFizzBuzzParameters;

final readonly class FizzBuzzParameters
{
    public function __construct(
        public int $int1,
        public int $int2,
        public int $limit,
        public string $str1,
        public string $str2,
    ) {
        if ($int1 < 1 || $int2 < 1 || $limit < 1) {
            throw new InvalidFizzBuzzParameters('int1, int2 and limit must be greater than or equal to 1.');
        }
    }
}
