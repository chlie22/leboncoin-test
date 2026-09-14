<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Logging;

use App\Shared\Infrastructure\Logging\RedactQueryStringProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * Query strings (str1/str2) must not remain in application log payloads (docs/conception.md §8.1).
 */
final class RedactQueryStringProcessorTest extends TestCase
{
    public function testStripsQueryFromMessageAndContextStrings(): void
    {
        $processor = new RedactQueryStringProcessor();
        $record = $processor(new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'request',
            level: Level::Debug,
            message: 'Matched route "fizzbuzz_generate".',
            context: [
                'route' => 'fizzbuzz_generate',
                'url' => 'http://localhost/v1/fizzbuzz?str1=MARK_STR1_PRIVACY_9&str2=MARK_STR2_PRIVACY_9',
            ],
        ));

        self::assertSame('Matched route "fizzbuzz_generate".', $record->message);
        self::assertSame('http://localhost/v1/fizzbuzz', $record->context['url']);
        self::assertStringNotContainsString('MARK_STR1_PRIVACY_9', var_export($record->context, true));
    }

    public function testStripsQueryFromExceptionStyleMessage(): void
    {
        $processor = new RedactQueryStringProcessor();
        $record = $processor(new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'request',
            level: Level::Error,
            message: 'No route found for "GET http://localhost/no-such-route?str1=SECRET&str2=SECRET2"',
        ));

        self::assertSame('No route found for "GET http://localhost/no-such-route"', $record->message);
        self::assertStringNotContainsString('SECRET', $record->message);
    }
}
