<?php

namespace App\Services\Payroll;

use App\Services\Payroll\Formula\FormulaEvaluator;
use App\Services\Payroll\Formula\FormulaException;
use App\Services\Payroll\Formula\FormulaScope;
use App\Services\Payroll\Formula\FormulaValue;
use App\Services\Payroll\Formula\PayrollFormula;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The ONE calculation engine of the payroll sheet: the workbook template and every custom formula column run through it
 * (server rows, Home, exports and the preview all call this class).
 *
 * Input columns hold values; formula columns are evaluated in dependency order for each employee row. Each formula column's
 * result is rounded half away from zero at its own column boundary — 2 decimals for amounts, 6 for numbers and percentages —
 * before dependent columns use it. Nothing is clamped: a negative taxable base stays negative and is flagged as a warning.
 */
final class PayrollCalculator
{
    public const AMOUNT_SCALE = 2;

    public const NUMBER_SCALE = 6;

    public const STORAGE_SCALE = 6; // stored integers are value x 10^6

    /** @var array<string, array> column definitions by key, in dependency (evaluation) order for formula columns */
    private array $columns = [];

    /** @var array<string, array> parsed formula AST by column key */
    private array $asts = [];

    /** @var list<string> formula column keys in evaluation order */
    private array $order = [];

    /** @var array<string, BigDecimal> */
    private array $settings;

    /**
     * @param  list<array>  $columns  ['key','label','kind' input|formula,'value_type','formula' (canonical),'blank_as_zero','warn_negative']
     * @param  list<array>  $settings  ['key','label','value_type','value' => BigDecimal|string]
     */
    public function __construct(array $columns, array $settings)
    {
        foreach ($columns as $column) {
            $this->columns[$column['key']] = $column;
        }
        $this->settings = [];
        $entries = [];
        foreach ($settings as $setting) {
            $this->settings[$setting['key']] = BigDecimal::of((string) $setting['value']);
            $entries[] = ['key' => $setting['key'], 'label' => $setting['label'], 'type' => 'number', 'source' => 'setting'];
        }
        foreach ($columns as $column) {
            $entries[] = ['key' => $column['key'], 'label' => $column['label'], 'type' => $column['value_type'] === 'text' ? 'text' : 'number', 'source' => 'column'];
        }
        $scope = new FormulaScope($entries);
        $deps = [];
        foreach ($columns as $column) {
            if ($column['kind'] === 'formula') {
                $this->asts[$column['key']] = PayrollFormula::parse($column['formula'], $scope);
                $deps[$column['key']] = array_values(array_filter(PayrollFormula::references($this->asts[$column['key']]), fn ($k) => isset($this->columns[$k]) && $this->columns[$k]['kind'] === 'formula'));
            }
        }
        $this->order = self::topologicalOrder($deps);
    }

    /** @return list<string> keys ordered so every formula comes after the formulas it uses; throws on a cycle */
    public static function topologicalOrder(array $deps): array
    {
        $order = [];
        $state = [];
        $visit = function (string $key, array $path) use (&$visit, &$order, &$state, $deps): void {
            if (($state[$key] ?? 0) === 2) {
                return;
            }
            if (($state[$key] ?? 0) === 1) {
                throw new FormulaException('تبعية دائرية بين الأعمدة: '.implode(' ← ', array_merge($path, [$key])), 'circular');
            }
            $state[$key] = 1;
            foreach ($deps[$key] ?? [] as $dep) {
                $visit($dep, array_merge($path, [$key]));
            }
            $state[$key] = 2;
            $order[] = $key;
        };
        foreach (array_keys($deps) as $key) {
            $visit($key, []);
        }

        return $order;
    }

    public function ast(string $key): ?array
    {
        return $this->asts[$key] ?? null;
    }

    public function column(string $key): ?array
    {
        return $this->columns[$key] ?? null;
    }

    /** @return list<string> */
    public function formulaOrder(): array
    {
        return $this->order;
    }

    public function setting(string $key): BigDecimal
    {
        return $this->settings[$key];
    }

    /**
     * @param  array<string, BigDecimal|string|null>  $inputs  raw stored values by input column key (null/absent = blank)
     * @return array<string, array{v: ?string, st: ?string, m: ?string}> wire cells for every column
     */
    public function evaluateRow(array $inputs): array
    {
        /** @var array<string, FormulaValue> $values */
        $values = [];
        foreach ($this->columns as $key => $column) {
            if ($column['kind'] === 'input') {
                $values[$key] = $this->inputValue($column, $inputs[$key] ?? null);
            }
        }
        $resolve = function (string $key) use (&$values): FormulaValue {
            if (isset($this->settings[$key])) {
                return FormulaValue::number($this->settings[$key]);
            }

            return $values[$key] ?? FormulaValue::error('unknown_reference', 'مرجع غير معروف.');
        };
        foreach ($this->order as $key) {
            $column = $this->columns[$key];
            $result = FormulaEvaluator::evaluate($this->asts[$key], $resolve);
            if ($result->isNumber()) {
                $result = FormulaValue::number($result->value->toScale($column['value_type'] === 'amount' ? self::AMOUNT_SCALE : self::NUMBER_SCALE, RoundingMode::HALF_UP));
            }
            $values[$key] = $result;
        }

        $cells = [];
        foreach ($this->columns as $key => $column) {
            $cells[$key] = $this->cell($column, $values[$key], $inputs[$key] ?? null);
        }

        return $cells;
    }

    private function inputValue(array $column, BigDecimal|string|null $raw): FormulaValue
    {
        if ($column['value_type'] === 'text') {
            return FormulaValue::text($raw === null ? '' : (string) $raw);
        }
        if ($raw === null) {
            return $column['blank_as_zero'] ? FormulaValue::number(0) : FormulaValue::missing('مدخل ناقص: '.$column['label']);
        }

        return FormulaValue::number($raw instanceof BigDecimal ? $raw : BigDecimal::of($raw));
    }

    private function cell(array $column, FormulaValue $value, BigDecimal|string|null $raw): array
    {
        if ($value->state !== 'ok') {
            return ['v' => null, 'st' => $value->state, 'm' => $value->message];
        }
        if ($column['kind'] === 'input' && $raw === null) {
            return ['v' => null, 'st' => null, 'm' => null]; // a blank optional input stays blank (counted as zero only in calculations)
        }
        if (! $value->isNumber()) {
            return ['v' => (string) $value->value, 'st' => null, 'm' => null];
        }
        $text = self::format($value->value, $column['value_type']);
        $warn = ! empty($column['warn_negative']) && $value->value->isNegative();

        return ['v' => $text, 'st' => $warn ? 'warning' : null, 'm' => $warn ? 'قيمة سالبة: لا يوجد حدّ أدنى صفري في المعادلة (تحذير حسابي).' : null];
    }

    /** Wire/text form: amounts always two decimals; numbers and percentages without trailing zeros. */
    public static function format(BigDecimal $v, string $type): string
    {
        if ($type === 'amount') {
            return (string) $v->toScale(self::AMOUNT_SCALE, RoundingMode::HALF_UP);
        }

        return self::plain($v->toScale(self::NUMBER_SCALE, RoundingMode::HALF_UP));
    }

    /** Plain decimal text without trailing zeros ("0.07", "1200", "0"). */
    public static function plain(BigDecimal $v): string
    {
        $text = (string) $v;
        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text === '' || $text === '-0' ? '0' : $text;
    }

    /** Integer stored for a numeric input: value x 10^6 (exact; rejects more than 6 decimals). */
    public static function toStored(BigDecimal $v): int
    {
        return $v->toScale(self::STORAGE_SCALE, RoundingMode::UNNECESSARY)->getUnscaledValue()->toInt();
    }

    public static function fromStored(int $stored): BigDecimal
    {
        return BigDecimal::ofUnscaledValue($stored, self::STORAGE_SCALE);
    }
}
