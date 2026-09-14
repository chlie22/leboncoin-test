<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * OpenAPI Str1/Str2 schema (docs/openapi.yaml): minLength 1, maxLength 50, pattern ^\P{Cc}+$ (ECMA-262).
 * Used by functional tests (R02) so PHP validation and the contract agree on accept/reject.
 */
final class OpenApiStringContract
{
    public static function accepts(string $value): bool
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            return false;
        }

        $length = mb_strlen($value, 'UTF-8');
        if ($length < 1 || $length > 50) {
            return false;
        }

        // OpenAPI uses ECMA-262 `$` (rejects a trailing newline). PHP's `$` would accept it, so use `\z`.
        return 1 === preg_match('/^\P{Cc}+\z/u', $value);
    }
}
