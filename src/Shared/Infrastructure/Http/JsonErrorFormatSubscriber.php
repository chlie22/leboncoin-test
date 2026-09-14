<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Forces the JSON request format so Symfony's error renderer emits RFC 9457 bodies for 404/405
 * and mapped exceptions, then sets Content-Type to application/problem+json (docs/conception.md §4.4).
 *
 * SerializerErrorRenderer serializes with format "json" (JsonEncoder) and would otherwise leave
 * Content-Type as application/json; the response pass rewrites it for error statuses.
 */
final class JsonErrorFormatSubscriber implements EventSubscriberInterface
{
    public function onKernelRequest(RequestEvent $event): void
    {
        $event->getRequest()->setRequestFormat('json');
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        if ($response->getStatusCode() < 400) {
            return;
        }

        $contentType = $response->headers->get('Content-Type', '');
        if (str_starts_with($contentType, 'application/json')) {
            $response->headers->set('Content-Type', 'application/problem+json');
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 1024],
            KernelEvents::RESPONSE => ['onKernelResponse', -1024],
        ];
    }
}
