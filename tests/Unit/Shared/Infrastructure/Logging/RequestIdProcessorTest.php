<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Logging;

use App\Shared\Infrastructure\Logging\RequestIdProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * RequestIdProcessor adds Nginx's X-Request-Id to every Monolog record (docs/conception.md §8.1).
 */
final class RequestIdProcessorTest extends TestCase
{
    public function testAddsRequestIdFromHeaderToExtra(): void
    {
        $stack = new RequestStack();
        $request = Request::create('/healthz');
        $request->headers->set('X-Request-Id', 'abc-123');
        $stack->push($request);

        $processor = new RequestIdProcessor($stack);
        $record = $processor(new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Warning,
            message: 'hello',
        ));

        self::assertSame('abc-123', $record->extra['request_id']);
    }

    public function testLeavesExtraWithoutRequestIdWhenHeaderMissing(): void
    {
        $stack = new RequestStack();
        $stack->push(Request::create('/healthz'));

        $processor = new RequestIdProcessor($stack);
        $record = $processor(new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Warning,
            message: 'hello',
        ));

        self::assertArrayNotHasKey('request_id', $record->extra);
    }

    public function testLeavesExtraWithoutRequestIdWhenNoRequest(): void
    {
        $processor = new RequestIdProcessor(new RequestStack());
        $record = $processor(new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Warning,
            message: 'hello',
        ));

        self::assertArrayNotHasKey('request_id', $record->extra);
    }
}
