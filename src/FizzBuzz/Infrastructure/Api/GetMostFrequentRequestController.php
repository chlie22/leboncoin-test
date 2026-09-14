<?php

declare(strict_types=1);

namespace App\FizzBuzz\Infrastructure\Api;

use App\FizzBuzz\Application\GetMostFrequentRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET|HEAD /v1/stats — most frequent request in the sliding window (docs/conception.md §4.2).
 * Query parameters are ignored. HEAD still executes the use case so a down store yields 503.
 */
#[Route('/v1/stats', name: 'fizzbuzz_stats', methods: ['GET', 'HEAD'])]
final readonly class GetMostFrequentRequestController
{
    public function __construct(private GetMostFrequentRequest $getMostFrequentRequest)
    {
    }

    public function __invoke(Request $request): Response
    {
        $stats = $this->getMostFrequentRequest->execute();
        if ($request->isMethod('HEAD')) {
            return new Response(status: Response::HTTP_OK);
        }

        $payload = [
            'request' => null === $stats->request ? null : [
                'int1' => $stats->request->int1,
                'int2' => $stats->request->int2,
                'limit' => $stats->request->limit,
                'str1' => $stats->request->str1,
                'str2' => $stats->request->str2,
            ],
            'hits' => $stats->hits,
            'window' => [
                'type' => 'last_requests',
                'size' => $stats->windowSize,
                'count' => $stats->windowCount,
            ],
        ];

        $json = json_encode(
            $payload,
            JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );

        return JsonResponse::fromJsonString($json);
    }
}
