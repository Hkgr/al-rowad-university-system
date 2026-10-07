<?php

namespace App\Services\Payroll\Formula;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * Exact decimal evaluation of a parsed formula (brick/math; no floating point).
 *
 * Rules (identical on the client and in the Excel export):
 *  - A reference to a blank "required" input, or to a formula result that is itself unavailable, makes the whole
 *    formula UNAVAILABLE ("missing") — decided statically from every reference in the formula, whichever IF branch would run.
 *    A referenced error propagates as that error. Blank optional inputs count as zero (the stored blank is untouched).
 *  - Division by zero is an error at the affected cell. IF evaluates only the chosen branch, so IF([b]=0, 0, [a]/[b]) is safe.
 *  - Division keeps 10 decimals (half up); everything else is exact. The column wrapper (PayrollCalculator) then rounds
 *    the result half away from zero to 2 decimals for amounts and 6 for numbers/percentages.
 *  - Results of 10^15 or more are an overflow error.
 */
final class FormulaEvaluator
{
    public const DIVISION_SCALE = 10;

    public const LIMIT = '1000000000000000';

    /**
     * @param  callable(string):FormulaValue  $resolve  value of a referenced column or setting
     * @param  (callable(BigDecimal):void)|null  $trace  receives the exact value of every numeric node (used to judge Excel's precision limit)
     */
    public static function evaluate(array $ast, callable $resolve, ?callable $trace = null): FormulaValue
    {
        $cache = [];
        $get = function (string $key) use (&$cache, $resolve): FormulaValue {
            return $cache[$key] ??= $resolve($key);
        };
        $errors = [];
        $missing = [];
        foreach (PayrollFormula::references($ast) as $key) {
            $v = $get($key);
            if ($v->state === 'error') {
                $errors[] = $v;
            } elseif ($v->state === 'missing') {
                $missing[] = $v;
            }
        }
        if ($errors !== []) {
            return $errors[0];
        }
        if ($missing !== []) {
            return FormulaValue::missing(implode('، ', array_unique(array_map(fn (FormulaValue $m) => $m->message, $missing))));
        }

        try {
            $result = self::run($ast, $get, $trace);
        } catch (MathException) {
            return FormulaValue::error('overflow', 'تعذّر حساب القيمة.');
        }
        if ($result->isNumber() && $result->value->abs()->isGreaterThanOrEqualTo(self::LIMIT)) {
            return FormulaValue::error('overflow', 'القيمة الناتجة كبيرة جدًا.');
        }

        return $result;
    }

    private static function run(array $n, callable $get, ?callable $trace = null): FormulaValue
    {
        $result = self::node($n, $get, $trace);
        if ($trace !== null && $result->isNumber()) {
            $trace($result->value);
        }

        return $result;
    }

    private static function node(array $n, callable $get, ?callable $trace): FormulaValue
    {
        switch ($n['t']) {
            case 'num':
                return FormulaValue::number($n['v']);
            case 'str':
                return FormulaValue::text($n['v']);
            case 'ref':
                return $get($n['k']);
            case 'neg':
                $a = self::run($n['a'], $get, $trace);

                return $a->ok() ? FormulaValue::number($a->value->negated()) : $a;
            case 'pct':
                $a = self::run($n['a'], $get, $trace);

                return $a->ok() ? FormulaValue::number($a->value->withPointMovedLeft(2)) : $a;
            case 'bin':
                $l = self::run($n['l'], $get, $trace);
                if (! $l->ok()) {
                    return $l;
                }
                $r = self::run($n['r'], $get, $trace);
                if (! $r->ok()) {
                    return $r;
                }

                return match ($n['op']) {
                    '+' => FormulaValue::number($l->value->plus($r->value)),
                    '-' => FormulaValue::number($l->value->minus($r->value)),
                    '*' => FormulaValue::number($l->value->multipliedBy($r->value)),
                    '/' => $r->value->isZero()
                        ? FormulaValue::error('div0', 'قسمة على صفر')
                        : FormulaValue::number($l->value->dividedBy($r->value, self::DIVISION_SCALE, RoundingMode::HALF_UP)),
                };
            case 'cmp':
                $l = self::run($n['l'], $get, $trace);
                if (! $l->ok()) {
                    return $l;
                }
                $r = self::run($n['r'], $get, $trace);
                if (! $r->ok()) {
                    return $r;
                }
                $c = $l->value instanceof BigDecimal ? $l->value->compareTo($r->value) : strcmp((string) $l->value, (string) $r->value);

                return FormulaValue::bool(match ($n['op']) {
                    '=' => $c === 0, '<>' => $c !== 0, '<' => $c < 0, '<=' => $c <= 0, '>' => $c > 0, '>=' => $c >= 0,
                });
            case 'call':
                return self::call($n, $get, $trace);
        }

        return FormulaValue::error('syntax', 'عنصر غير معروف.');
    }

    private static function call(array $n, callable $get, ?callable $trace): FormulaValue
    {
        if ($n['f'] === 'IF') {
            $cond = self::run($n['args'][0], $get, $trace);
            if (! $cond->ok()) {
                return $cond;
            }

            return self::run($n['args'][$cond->value ? 1 : 2], $get, $trace);
        }
        $values = [];
        foreach ($n['args'] as $a) {
            $v = self::run($a, $get, $trace);
            if (! $v->ok()) {
                return $v;
            }
            $values[] = $v->value;
        }
        switch ($n['f']) {
            case 'SUM':
                return FormulaValue::number(array_reduce($values, fn (BigDecimal $c, BigDecimal $v) => $c->plus($v), BigDecimal::zero()));
            case 'MAX':
                return FormulaValue::number(array_reduce($values, fn (?BigDecimal $c, BigDecimal $v) => $c === null || $v->isGreaterThan($c) ? $v : $c));
            case 'MIN':
                return FormulaValue::number(array_reduce($values, fn (?BigDecimal $c, BigDecimal $v) => $c === null || $v->isLessThan($c) ? $v : $c));
            case 'ROUND':
                return FormulaValue::number($values[0]->toScale((int) $n['args'][1]['v'], RoundingMode::HALF_UP));
        }

        return FormulaValue::error('syntax', 'دالة غير معروفة.');
    }
}
