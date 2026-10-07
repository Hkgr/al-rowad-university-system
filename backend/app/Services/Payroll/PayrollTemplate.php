<?php

namespace App\Services\Payroll;

/**
 * The one authoritative definition of the template column whose formula an owner may edit.
 *
 * `total_net_payable` is the figure Home, the totals, the filters/sorts and both exports call "net payable". Its stable key, amount type,
 * calculated (read-only) nature, aggregation and the impossibility of deleting it stay protected; only its formula can be changed, and
 * restored to the default below. The default is stored here ONLY — the API sends it to the client for display (never duplicated in the UI).
 * (The migration seeds the same text as a historical snapshot; a test asserts it equals this constant.)
 */
final class PayrollTemplate
{
    public const TOTAL_NET_PAYABLE = 'total_net_payable';

    /** Canonical (stable-key) default: net salary + net compensation − other deductions. */
    public const NET_PAYABLE_FORMULA = '{net_salary} + {net_compensation} - {other_deductions}';

    /** Template columns whose formula (and only that) may be changed, with the formula to restore. */
    public const EDITABLE_FORMULAS = [self::TOTAL_NET_PAYABLE => self::NET_PAYABLE_FORMULA];

    public static function isFormulaEditable(string $key): bool
    {
        return array_key_exists($key, self::EDITABLE_FORMULAS);
    }

    public static function defaultFormula(string $key): ?string
    {
        return self::EDITABLE_FORMULAS[$key] ?? null;
    }
}
