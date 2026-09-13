<?php

declare(strict_types=1);

namespace App\FizzBuzz\Domain;

final class FizzBuzzGenerator
{
    /** @return list<string> */
    public function generate(FizzBuzzParameters $parameters): array
    {
        $result = [];
        for ($i = 1; $i <= $parameters->limit; ++$i) {
            $matched = false;
            $value = '';
            if (0 === $i % $parameters->int1) {
                $value .= $parameters->str1;
                $matched = true;
            }
            if (0 === $i % $parameters->int2) {
                $value .= $parameters->str2;
                $matched = true;
            }
            $result[] = $matched ? $value : (string) $i;
        }

        return $result;
    }
}
