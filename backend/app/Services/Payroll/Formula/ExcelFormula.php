<?php

namespace App\Services\Payroll\Formula;

/**
 * Translates a payroll formula AST into an Excel formula with the same meaning:
 *  - references become same-row cells (relative) or labelled settings cells (absolute);
 *  - the column's rounding (ROUND(...,2) for amounts, 6 otherwise) wraps the expression;
 *  - a required (non-optional) referenced input that is blank, or a referenced formula cell that is unavailable (""), makes the
 *    result "" exactly like the application's "unavailable" state; a referenced error propagates because OR() propagates it.
 */
final class ExcelFormula
{
    /**
     * @param  callable(string):string  $cellRef  Excel address for a key (e.g. G6 or $B$4)
     * @param  list<string>  $strictKeys  referenced keys that must be non-blank (required inputs and formula columns)
     */
    public static function build(array $ast, string $valueType, callable $cellRef, array $strictKeys): string
    {
        // Excel computes in binary floating point. Rounding to 6 decimals first removes the representation noise (e.g.
        // 4539.5*0.15-680.93 = -0.00499999999999545 instead of the exact -0.005) so exact ties round exactly like the
        // application's decimal arithmetic. 6 decimals keep 15 significant digits up to 999,999,999.999999.
        $expression = 'ROUND('.self::expr($ast, $cellRef).',6)';
        $body = $valueType === 'amount' ? 'ROUND('.$expression.',2)' : $expression;
        if ($strictKeys === []) {
            return '='.$body;
        }
        $guards = implode(',', array_map(fn (string $key) => $cellRef($key).'=""', $strictKeys));

        return '=IF(OR('.$guards.'),"",'.$body.')';
    }

    private static function expr(array $n, callable $cellRef): string
    {
        switch ($n['t']) {
            case 'num':
                return $n['v'];
            case 'str':
                return '"'.str_replace('"', '""', $n['v']).'"';
            case 'ref':
                return $cellRef($n['k']);
            case 'neg':
                return '(-'.self::expr($n['a'], $cellRef).')';
            case 'pct':
                return '('.self::expr($n['a'], $cellRef).'/100)';
            case 'bin':
                return '('.self::expr($n['l'], $cellRef).$n['op'].self::expr($n['r'], $cellRef).')';
            case 'cmp':
                return '('.self::expr($n['l'], $cellRef).$n['op'].self::expr($n['r'], $cellRef).')';
            case 'call':
                return $n['f'].'('.implode(',', array_map(fn ($a) => self::expr($a, $cellRef), $n['args'])).')';
        }

        return '0';
    }
}
