<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Monolog\LogRecord;

/**
 * Strips query strings from Monolog messages and string context so str1/str2 never appear in application logs
 * (docs/conception.md §8.1). Symfony may embed the full request URI (e.g. "Matched route" debug lines, 404 messages).
 */
final class RedactQueryStringProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $record = $record->with(
            message: self::stripQuery($record->message),
            context: self::walk($record->context),
        );
        $record->extra = self::walk($record->extra);

        return $record;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function walk(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (\is_string($value)) {
                $out[$key] = self::stripQuery($value);
            } elseif (\is_array($value)) {
                $out[$key] = self::walk($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private static function stripQuery(string $value): string
    {
        return preg_replace('/\?[^"\s]*/', '', $value) ?? $value;
    }
}
