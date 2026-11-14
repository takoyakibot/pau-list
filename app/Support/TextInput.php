<?php

namespace App\Support;

/**
 * Normalizes free-text input the same way everywhere (proposal save, creation start): invalid UTF-8 is
 * scrubbed, control and invisible characters are removed, surrounding (full-width) spaces are trimmed,
 * and a value that is empty afterwards becomes null. Non-strings are returned unchanged for the validator.
 */
final class TextInput
{
    /**
     * Bidi overrides/isolates and zero-width characters (can hide or reorder text).
     * U+200D (ZWJ) is kept because joined emoji need it; a value of only ZWJ counts as empty.
     */
    private const INVISIBLE = '\x{200B}\x{200C}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}';

    public static function singleLine(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return self::finish(preg_replace('/[\p{Cc}'.self::INVISIBLE.']/u', '', mb_scrub($value, 'UTF-8')));
    }

    public static function multiLine(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $value = str_replace(["\r\n", "\r"], "\n", mb_scrub($value, 'UTF-8'));

        return self::finish(preg_replace('/[^\P{Cc}\n\t]|['.self::INVISIBLE.']/u', '', $value));
    }

    private static function finish(?string $value): ?string
    {
        $value = preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', (string) $value);

        return $value === null || preg_match('/^[\s\x{3000}\x{200D}]*$/u', $value) === 1 ? null : $value;
    }
}
