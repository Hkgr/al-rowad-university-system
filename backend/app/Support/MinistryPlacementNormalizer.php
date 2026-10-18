<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

final class MinistryPlacementNormalizer
{
    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    public static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', (string) $value) ?? '';

        return $trimmed === '' ? null : $trimmed;
    }

    public static function duplicateKey(?string $identifier): ?string
    {
        if ($identifier === null) {
            return null;
        }
        $asciiDigits = strtr($identifier, self::DIGITS);
        $withoutWhitespace = preg_replace('/[\s\p{Z}]+/u', '', $asciiDigits) ?? '';

        return $withoutWhitespace === '' ? null : $withoutWhitespace;
    }

    public static function headerKey(mixed $value): string
    {
        $value = mb_strtolower(self::text($value) ?? '', 'UTF-8');
        $value = strtr($value, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه',
            '_' => '', '-' => '', '/' => '', '\\' => '', '.' => '', ':' => '',
        ]);

        return preg_replace('/[\s\p{Z}\p{M}]+/u', '', $value) ?? '';
    }

    /** @return array{value: ?string, error: ?string} */
    public static function date(array $cell): array
    {
        $display = self::text($cell['formatted'] ?? null);
        $raw = $cell['raw'] ?? null;
        if ($display === null && ($raw === null || $raw === '')) {
            return ['value' => null, 'error' => null];
        }

        if (($cell['data_type'] ?? null) === 'n' && (is_int($raw) || is_float($raw))) {
            try {
                $date = ExcelDate::excelToDateTimeObject((float) $raw, new DateTimeZone('UTC'));

                return ['value' => $date->format('Y-m-d'), 'error' => null];
            } catch (\Throwable) {
                return ['value' => null, 'error' => 'invalid_date'];
            }
        }

        $candidate = strtr($display ?? '', self::DIGITS);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $candidate, $parts) === 1) {
            if (checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                return ['value' => sprintf('%04d-%02d-%02d', $parts[1], $parts[2], $parts[3]), 'error' => null];
            }
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $candidate, $parts) === 1) {
            $day = (int) $parts[1];
            $month = (int) $parts[2];
            $year = (int) $parts[3];
            if ($day === $month && checkdate($month, $day, $year)) {
                return ['value' => sprintf('%04d-%02d-%02d', $year, $month, $day), 'error' => null];
            }
            if ($day > 12 && checkdate($month, $day, $year)) {
                return ['value' => sprintf('%04d-%02d-%02d', $year, $month, $day), 'error' => null];
            }

            return ['value' => null, 'error' => $day <= 12 && $month <= 12 ? 'ambiguous_date' : 'invalid_date'];
        }

        return ['value' => null, 'error' => 'invalid_date'];
    }

    /** @return array{value: ?bool, error: ?string} */
    public static function boolean(array $cell): array
    {
        $display = self::text($cell['formatted'] ?? null);
        if ($display === null) {
            return ['value' => false, 'error' => null];
        }
        $candidate = mb_strtolower(strtr($display, self::DIGITS), 'UTF-8');
        if (in_array($candidate, ['1', 'true', 'yes', 'نعم'], true)) {
            return ['value' => true, 'error' => null];
        }
        if (in_array($candidate, ['0', 'false', 'no', 'لا'], true)) {
            return ['value' => false, 'error' => null];
        }

        return ['value' => null, 'error' => 'invalid_boolean'];
    }

    /** @return array{value: ?string, error: ?string} */
    public static function score(array $cell): array
    {
        $raw = $cell['raw'] ?? null;
        $display = self::text($cell['formatted'] ?? null);
        if (($raw === null || $raw === '') && $display === null) {
            return ['value' => null, 'error' => null];
        }

        $candidate = is_numeric($raw)
            ? rtrim(rtrim(sprintf('%.10F', (float) $raw), '0'), '.')
            : strtr($display ?? '', self::DIGITS + ['٫' => '.', '٬' => '', ',' => '']);
        if (preg_match('/^\d{1,3}(?:\.\d{1,3})?$/', $candidate) !== 1 || (float) $candidate > 999.999) {
            return ['value' => null, 'error' => 'invalid_score'];
        }

        return ['value' => number_format((float) $candidate, 3, '.', ''), 'error' => null];
    }

    public static function currentYearUtc(): int
    {
        return (int) (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y');
    }
}
