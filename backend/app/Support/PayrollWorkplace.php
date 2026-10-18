<?php

namespace App\Support;

/** The four payroll workplace choices; "other" carries a required free-text location. */
final class PayrollWorkplace
{
    public const AFRIN = 'afrin';

    public const JARABLUS = 'jarablus';

    public const BOTH = 'afrin_jarablus';

    public const OTHER = 'other';

    public const LABELS = [
        self::AFRIN => 'عفرين',
        self::JARABLUS => 'جرابلس',
        self::BOTH => 'عفرين وجرابلس',
        self::OTHER => 'أخرى',
    ];

    public static function codes(): array
    {
        return array_keys(self::LABELS);
    }

    /** What the grid and exports show: the custom text for "other", otherwise the fixed label. */
    public static function display(string $code, ?string $other): string
    {
        if ($code === self::OTHER) {
            return $other !== null && $other !== '' ? $other : self::LABELS[self::OTHER];
        }

        return self::LABELS[$code] ?? $code;
    }
}
