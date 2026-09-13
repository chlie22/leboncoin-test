<?php

declare(strict_types=1);

namespace App\FizzBuzz\Infrastructure\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

final class GenerateFizzBuzzController
{
    #[Route('/v1/fizzbuzz', methods: ['GET'])]
    public function __invoke(#[MapQueryString] GenerateFizzBuzzQuery $query): JsonResponse
    {
        $result = [];
        for ($i = 1; $i <= $query->limit; ++$i) {
            $result[] = match (true) {
                0 === $i % ($query->int1 * $query->int2) => $query->str1 . $query->str2,
                0 === $i % $query->int1 => $query->str1,
                0 === $i % $query->int2 => $query->str2,
                default => (string) $i,
            };
        }

        return new JsonResponse($result);
    }
}
