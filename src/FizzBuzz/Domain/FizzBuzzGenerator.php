<?php

declare(strict_types=1);

namespace App\FizzBuzz\Domain;

final readonly class FizzBuzzGenerator
{
    /**
     * Two independent divisibility tests; a match is tracked by a flag, never inferred from the produced string,
     * so an empty or "0" replacement is still returned.
     *
     * @return list<string>
     */
    public function generate(FizzBuzzParameters $parameters): array
    {
        $sequence = [];

        for ($number = 1; $number <= $parameters->limit; ++$number) {
            $matched = false;
            $term = '';

            if (0 === $number % $parameters->int1) {
                $term .= $parameters->str1;
                $matched = true;
            }

            if (0 === $number % $parameters->int2) {
                $term .= $parameters->str2;
                $matched = true;
            }

            $sequence[] = $matched ? $term : (string) $number;
        }

        return $sequence;
    }
}
