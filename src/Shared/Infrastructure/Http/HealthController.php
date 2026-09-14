<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET|HEAD /healthz — readiness probe; degraded when statistics storage is unavailable (docs/conception.md §4.3).
 *
 * Uses Doctrine DBAL directly: Shared must not depend on src/FizzBuzz (Deptrac).
 */
#[Route('/healthz', name: 'health', methods: ['GET', 'HEAD'])]
final readonly class HealthController
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(Request $request): Response
    {
        $statistics = $this->checkStatistics();
        $status = 'ok' === $statistics ? 'ok' : 'degraded';

        if ($request->isMethod('HEAD')) {
            return new Response(status: Response::HTTP_OK);
        }

        $json = json_encode(
            ['status' => $status, 'checks' => ['statistics' => $statistics]],
            JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );

        return JsonResponse::fromJsonString($json);
    }

    private function checkStatistics(): string
    {
        try {
            $this->connection->executeQuery('SELECT 1 FROM fizzbuzz_request_log LIMIT 1');
            $this->connection->executeQuery('SELECT 1 FROM fizzbuzz_request_stat LIMIT 1');

            return 'ok';
        } catch (DbalException) {
            return 'unavailable';
        }
    }
}
