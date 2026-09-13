<?php

declare(strict_types=1);

namespace App\FizzBuzz\Domain;

use App\FizzBuzz\Domain\Exception\InvalidFizzBuzzParameters;

/**
 * The five values of a FizzBuzz request, identified by their values.
 *
 * Only the business invariants live here: operational bounds (limit, string length, control characters)
 * belong to the HTTP contract.
 */
final readonly class FizzBuzzParameters
{
    /**
     * @throws InvalidFizzBuzzParameters when int1, int2 or limit is lower than 1
     */
    public function __construct(
        public int $int1,
        public int $int2,
        public int $limit,
        public string $str1,
        public string $str2,
    ) {
        self::assertAtLeastOne('int1', $int1);
        self::assertAtLeastOne('int2', $int2);
        self::assertAtLeastOne('limit', $limit);
    }

    private static function assertAtLeastOne(string $name, int $value): void
    {
        if ($value < 1) {
            throw new InvalidFizzBuzzParameters(\sprintf('%s must be greater than or equal to 1, got %d.', $name, $value));
        }
    }
}
