<?php

namespace App\Services\Payroll;

/** Small text normaliser for payroll user input. */
final class PayrollText
{
    /** Trim (including Unicode spaces); blank/non-string => null. */
    public static function clean(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $value = preg_replace('/^[\s\p{Z}\x{200B}-\x{200F}\x{FEFF}]+|[\s\p{Z}\x{200B}-\x{200F}\x{FEFF}]+$/u', '', $value);

        return $value === null || $value === '' ? null : $value;
    }

    public static function hasControlCharacters(string $value): bool
    {
        return (bool) preg_match('/[\x00-\x1F\x7F]/u', $value) || ! mb_check_encoding($value, 'UTF-8');
    }
}
