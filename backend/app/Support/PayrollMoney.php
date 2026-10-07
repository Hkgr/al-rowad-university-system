<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact USD arithmetic for the payroll sheet. Amounts are integer cents everywhere (storage, calculation,
 * totals); strings with two decimals are used only at the API/export edge. No binary floating point.
 */
final class PayrollMoney
{
    /** 999,999,999.99 */
    public const MAX_CENTS = 99_999_999_999;

    public const AMOUNT_PATTERN = '/^\d{1,9}(\.\d{1,2})?$/';

    /**
     * Parse an input amount into cents. null/'' (blank) => null. Rejects negatives, exponents, separators,
     * more than two decimals, non-ASCII digits and floats.
     */
    public static function parse(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            if ($value < 0 || $value > intdiv(self::MAX_CENTS, 100)) {
                throw new InvalidArgumentException('out_of_range');
            }

            return $value * 100;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException('not_a_decimal_string');
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (! preg_match(self::AMOUNT_PATTERN, $value)) {
            throw new InvalidArgumentException(str_starts_with($value, '-') ? 'negative' : 'invalid');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $cents = ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');
        if ($cents > self::MAX_CENTS) {
            throw new InvalidArgumentException('out_of_range');
        }

        return $cents;
    }

    /** Signed two-decimal string, e.g. -12.50. null stays null. */
    public static function format(?int $cents): ?string
    {
        if ($cents === null) {
            return null;
        }
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    /** Net payable = fixed salary - deduction + compensation; blank salary => blank; blank deduction/compensation => 0. */
    public static function payable(?int $salary, ?int $deduction, ?int $compensation): ?int
    {
        return $salary === null ? null : $salary - ($deduction ?? 0) + ($compensation ?? 0);
    }
}
