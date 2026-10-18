<?php

namespace App\Services\Payroll\Formula;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Where an exported Excel formula can honestly differ from the application.
 *
 * Excel stores a number as a binary double (about 15 significant decimal digits). ExcelFormula removes the representation noise of values
 * that have at most 14 decimals, which makes every cell equal to the application's exact decimal result — EXCEPT when the exact result
 * cannot be told from a rounding boundary within the doubles' accuracy, or cannot be written with 15 significant digits at all. This class
 * decides, per cell and from the exact values, whether one of those two cases applies, so the export can flag the cell instead of claiming
 * equality:
 *  (a) the rounded result needs more than 15 significant digits (for an amount: 10^13 or more), or
 *  (b) the exact pre-rounding value lies closer to a half-unit rounding boundary than the accumulated floating-point error of the formula
 *      (conservatively 2^-45 of the largest intermediate magnitude, about 3e-14 relative).
 */
final class PayrollPrecision
{
    public const SIGNIFICANT_DIGITS = 15;

    public const RELATIVE_NOISE = '0.00000000000003'; // ≈ 2^-45

    /**
     * @param  callable(string):FormulaValue  $resolve  exact value of every referenced column/setting of this row
     */
    public static function atRisk(array $ast, callable $resolve, int $columnScale): bool
    {
        $largest = BigDecimal::zero();
        $result = FormulaEvaluator::evaluate($ast, $resolve, function (BigDecimal $value) use (&$largest): void {
            if ($value->abs()->isGreaterThan($largest)) {
                $largest = $value->abs();
            }
        });
        if (! $result->isNumber()) {
            return false;
        }
        $exact = $result->value;
        $rounded = $exact->toScale($columnScale, RoundingMode::HALF_UP);
        if (self::digits($rounded) > self::SIGNIFICANT_DIGITS) {
            return true;
        }
        // Distance of the exact value from the nearest half-unit boundary of the column scale.
        $scaled = $exact->abs()->withPointMovedRight($columnScale);
        $fraction = $scaled->minus($scaled->toScale(0, RoundingMode::DOWN));
        $distance = $fraction->minus('0.5')->abs()->withPointMovedLeft($columnScale);

        return $distance->isLessThan($largest->multipliedBy(self::RELATIVE_NOISE));
    }

    /** Significant digits of a decimal text such as 123456789012345.67 (integer digits plus decimals). */
    public static function digits(BigDecimal $value): int
    {
        return strlen(ltrim(str_replace(['-', '.'], '', (string) $value->abs()), '0'));
    }
}
