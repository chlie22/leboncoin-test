<?php

declare(strict_types=1);

namespace App\FizzBuzz\Domain;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class FizzBuzzParameters
{
    public function __construct(
        #[Assert\Range(min: 1)]
        public int $int1,
        #[Assert\Range(min: 1)]
        public int $int2,
        #[Assert\Range(min: 1, max: 10000)]
        public int $limit,
        public string $str1,
        public string $str2,
    ) {
    }
}
