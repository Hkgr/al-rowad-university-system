<?php

namespace App\Services\Payroll\Formula;

/**
 * Translates a payroll formula AST into an Excel formula with the same meaning as the application's exact decimal arithmetic.
 *
 * Application semantics being reproduced (see FormulaEvaluator / PayrollCalculator):
 *  - + − * and % are exact; division keeps 10 decimals (half up); an explicit ROUND(x, d) rounds half away from zero;
 *  - the column result is rounded ONCE, half away from zero, at the column's own scale (2 decimals for amounts, 6 otherwise) — directly
 *    from the exact value, never via an intermediate rounding;
 *  - a blank required input / unavailable referenced result makes the result unavailable ("").
 *
 * Excel computes in binary floating point, so a decimal such as 0.07 or 4539.5*0.15 is only approximate and an exact tie
 * (x.xx5) can land on either side of the rounding boundary. The translation therefore does not round to a fixed number of
 * decimals; it removes binary noise using the EXACT decimal scale every sub-expression has (known statically):
 *    scale(literal) = its decimals, scale(a+b) = max, scale(a*b) = sa+sb, scale(a%) = sa+2, scale(a/b) = 10 (the rounded quotient),
 *    scale(ROUND(x,d)) = d, scale(reference) = the declared scale of the referenced column or setting.
 * Where noise could matter (operands of * / comparisons / ROUND / MAX / MIN, and the final value) the value is snapped to its own scale with
 * ROUND(x, scale). A value whose exact result has at most `scale` decimals is unchanged by that snap except for its noise, so non-ties
 * are never moved across a boundary. The column's own rounding then runs on the clean value.
 *
 * Genuine Excel limit: a double carries about 15 significant digits. When an intermediate exact result needs more, the snap cannot be exact;
 * PayrollPrecision reports such cells so the export can flag them instead of claiming equality (see docs/owner-payroll.md §10).
 */
final class ExcelFormula
{
    /** Snapping to more decimals than a double can hold is pointless; such nodes are left as computed. */
    public const MAX_SNAP_SCALE = 14;

    /**
     * @param  callable(string):string  $cellRef  Excel address for a key (e.g. G6 or $B$4)
     * @param  list<string>  $strictKeys  referenced keys that must be non-blank (required inputs and formula columns)
     * @param  callable(string):int  $scaleOf  exact decimal scale of a referenced column or setting
     */
    public static function build(array $ast, string $valueType, callable $cellRef, array $strictKeys, callable $scaleOf): string
    {
        [$expression, $scale, $dirty] = self::emit($ast, $cellRef, $scaleOf);
        $columnScale = $valueType === 'amount' ? 2 : 6;
        $body = 'ROUND('.self::clean($expression, $scale, $dirty).','.$columnScale.')';
        if ($strictKeys === []) {
            return '='.$body;
        }
        $guards = implode(',', array_map(fn (string $key) => $cellRef($key).'=""', $strictKeys));

        return '=IF(OR('.$guards.'),"",'.$body.')';
    }

    /** Snap a possibly-noisy value to its exact scale. */
    private static function clean(string $expression, int $scale, bool $dirty): string
    {
        return $dirty && $scale <= self::MAX_SNAP_SCALE ? 'ROUND('.$expression.','.$scale.')' : $expression;
    }

    /** @return array{0: string, 1: int, 2: bool} Excel expression, its exact decimal scale, whether it may carry binary noise */
    private static function emit(array $n, callable $cellRef, callable $scaleOf): array
    {
        switch ($n['t']) {
            case 'num':
                return [$n['v'], strlen(explode('.', $n['v'])[1] ?? ''), false];
            case 'str':
                return ['"'.str_replace('"', '""', $n['v']).'"', 0, false];
            case 'ref':
                return [$cellRef($n['k']), $scaleOf($n['k']), false];
            case 'neg':
                [$e, $s, $d] = self::emit($n['a'], $cellRef, $scaleOf);

                return ['(-'.$e.')', $s, $d];
            case 'pct':
                [$e, $s, $d] = self::emit($n['a'], $cellRef, $scaleOf);

                return ['('.self::clean($e, $s, $d).'/100)', $s + 2, $n['a']['t'] !== 'num'];
            case 'bin':
                [$l, $ls, $ld] = self::emit($n['l'], $cellRef, $scaleOf);
                [$r, $rs, $rd] = self::emit($n['r'], $cellRef, $scaleOf);

                return match ($n['op']) {
                    '+', '-' => ['('.$l.$n['op'].$r.')', max($ls, $rs), true],
                    '*' => ['('.self::clean($l, $ls, $ld).'*'.self::clean($r, $rs, $rd).')', $ls + $rs, true],
                    // The application keeps 10 decimals (half up) of the quotient of the exact operands.
                    '/' => ['ROUND('.self::clean($l, $ls, $ld).'/'.self::clean($r, $rs, $rd).',10)', 10, false],
                };
            case 'cmp':
                [$l, $ls, $ld] = self::emit($n['l'], $cellRef, $scaleOf);
                [$r, $rs, $rd] = self::emit($n['r'], $cellRef, $scaleOf);

                return ['('.self::clean($l, $ls, $ld).$n['op'].self::clean($r, $rs, $rd).')', 0, false];
            case 'call':
                return self::call($n, $cellRef, $scaleOf);
        }

        return ['0', 0, false];
    }

    private static function call(array $n, callable $cellRef, callable $scaleOf): array
    {
        $args = array_map(fn ($a) => self::emit($a, $cellRef, $scaleOf), $n['args']);
        switch ($n['f']) {
            case 'ROUND': // the digits argument is a validated integer literal
                [$e, $s, $d] = $args[0];

                return ['ROUND('.self::clean($e, $s, $d).','.$n['args'][1]['v'].')', (int) $n['args'][1]['v'], false];
            case 'IF':
                [$c, $cs, $cd] = $args[0];

                return ['IF('.self::clean($c, $cs, $cd).','.$args[1][0].','.$args[2][0].')', max($args[1][1], $args[2][1]), $args[1][2] || $args[2][2]];
            case 'SUM':
                return ['SUM('.implode(',', array_column($args, 0)).')', max(array_column($args, 1)), true];
            default: // MAX / MIN compare their arguments, so those are snapped first
                $cleaned = array_map(fn ($a) => self::clean($a[0], $a[1], $a[2]), $args);

                return [$n['f'].'('.implode(',', $cleaned).')', max(array_column($args, 1)), false];
        }
    }
}
