<?php

namespace App\Services\Payroll;

/**
 * Authoritative restore definitions for the existing calculated template columns.
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
    public const EDITABLE_FORMULAS = [
        'salary_entitlement' => '{fixed_salary} + {salary_adjustment}',
        'salary_taxable_base' => '{salary_entitlement} - {insurance} - {tax_exemption}',
        'compensation_entitlement' => '{compensation} + {compensation_adjustment}',
        'combined_taxable_base' => '{salary_entitlement} + {compensation_entitlement} - {insurance} - {tax_exemption}',
        'insurance' => '{fixed_salary} * {insurance_rate}',
        'salary_tax' => '{salary_taxable_base} * {income_tax_rate}',
        'compensation_tax' => '{combined_taxable_base} * {income_tax_rate} - {salary_tax}',
        'total_deductions' => '{insurance} + {salary_tax} + {compensation_tax} + {other_deductions}',
        'gross_entitlement' => '{salary_entitlement} + {compensation_entitlement}',
        'net_salary' => '{salary_entitlement} - {insurance} - {salary_tax}',
        'net_compensation' => '{compensation_entitlement} - {compensation_tax}',
        self::TOTAL_NET_PAYABLE => self::NET_PAYABLE_FORMULA,
    ];

    public static function isFormulaEditable(string $key): bool
    {
        return array_key_exists($key, self::EDITABLE_FORMULAS);
    }

    public static function defaultFormula(string $key): ?string
    {
        return self::EDITABLE_FORMULAS[$key] ?? null;
    }
}
