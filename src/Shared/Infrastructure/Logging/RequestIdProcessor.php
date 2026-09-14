<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Adds the request_id transmitted by Nginx (X-Request-Id) to every Monolog record (docs/conception.md §8.1).
 */
final class RequestIdProcessor
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $request = $this->requestStack->getCurrentRequest();
        $requestId = $request?->headers->get('X-Request-Id');
        if (null !== $requestId && '' !== $requestId) {
            $record->extra['request_id'] = $requestId;
        }

        return $record;
    }
}
