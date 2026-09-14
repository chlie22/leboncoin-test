<?php

declare(strict_types=1);

namespace App\FizzBuzz\Infrastructure\Api;

use App\FizzBuzz\Application\GenerateFizzBuzz;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET|HEAD /v1/fizzbuzz — validate query, generate sequence, record statistics (docs/conception.md §3.5, §4.1).
 */
#[Route('/v1/fizzbuzz', name: 'fizzbuzz_generate', methods: ['GET', 'HEAD'])]
final readonly class GenerateFizzBuzzController
{
    public function __construct(private GenerateFizzBuzz $generateFizzBuzz)
    {
    }

    public function __invoke(
        Request $request,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        GenerateFizzBuzzQuery $query,
    ): Response {
        if ($request->isMethod('HEAD')) {
            return new Response(status: Response::HTTP_OK);
        }

        $sequence = $this->generateFizzBuzz->execute($query->toParameters());
        $json = json_encode(
            $sequence,
            JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );

        return JsonResponse::fromJsonString($json);
    }
}
