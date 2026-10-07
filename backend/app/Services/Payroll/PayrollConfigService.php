<?php

namespace App\Services\Payroll;

use App\Exceptions\PayrollException;
use App\Services\Payroll\Formula\FormulaException;
use App\Services\Payroll\Formula\FormulaScope;
use App\Services\Payroll\Formula\PayrollFormula;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Columns, formulas and global settings of the payroll working sheet.
 *
 * One configuration revision (payroll_config.revision) protects every change: a save carries the revision it was based on and
 * a stale one is a 409 that writes nothing. Each change is one transaction, attributed to the authenticated editor.
 */
class PayrollConfigService
{
    public const GROUPS = ['employee' => 'بيانات الموظف', 'salary' => 'الراتب', 'compensation' => 'التعويض', 'deductions' => 'الاقتطاعات', 'net' => 'الصافي'];

    public const TYPES = ['text' => 'نص', 'number' => 'رقم', 'amount' => 'مبلغ (ل.س)', 'percent' => 'نسبة مئوية'];

    public const MAX_CUSTOM_COLUMNS = 60;

    public const MAX_CHAIN = 30;

    public const MAX_INPUT = '999999999.99';

    /** System columns may only change presentation, never their meaning. */
    private const SYSTEM_EDITABLE = ['label', 'visible_grid', 'visible_export', 'compact', 'sort_order'];

    // ── reading ───────────────────────────────────────────────────────────

    /** @return array{revision:int, settings:list<array>, columns:list<array>} */
    public function load(): array
    {
        $settings = DB::table('payroll_settings')->orderBy('sort_order')->get()->map(fn ($s) => [
            'id' => (int) $s->id, 'key' => $s->key, 'label' => $s->label, 'value_type' => $s->value_type,
            'value' => PayrollCalculator::plain(PayrollCalculator::fromStored((int) $s->value_scaled)),
        ])->all();
        $groupIndex = array_flip(array_keys(self::GROUPS));
        $columns = DB::table('payroll_columns')->get()->map(fn ($c) => [
            'id' => (int) $c->id, 'key' => $c->key, 'label' => $c->label, 'group' => $c->column_group, 'kind' => $c->kind, 'value_type' => $c->value_type,
            'formula' => $c->formula, 'blank_as_zero' => (bool) $c->blank_as_zero, 'allow_negative' => (bool) $c->allow_negative,
            'warn_negative' => (bool) $c->warn_negative, 'aggregation' => $c->aggregation, 'visible_grid' => (bool) $c->visible_grid,
            'visible_export' => (bool) $c->visible_export, 'compact' => (bool) $c->compact, 'is_system' => (bool) $c->is_system, 'sort_order' => (int) $c->sort_order,
        ])->sortBy(fn ($c) => sprintf('%02d-%06d-%06d', $groupIndex[$c['group']] ?? 99, $c['sort_order'], $c['id']))->values()->all();

        return ['revision' => (int) DB::table('payroll_config')->where('id', 1)->value('revision'), 'settings' => $settings, 'columns' => $columns];
    }

    public function calculator(?array $config = null): PayrollCalculator
    {
        $config ??= $this->load();

        return new PayrollCalculator($config['columns'], $config['settings']);
    }

    public function scope(array $config): FormulaScope
    {
        $entries = array_map(fn ($s) => ['key' => $s['key'], 'label' => $s['label'], 'type' => 'number', 'source' => 'setting'], $config['settings']);
        foreach ($config['columns'] as $c) {
            $entries[] = ['key' => $c['key'], 'label' => $c['label'], 'type' => $c['value_type'] === 'text' ? 'text' : 'number', 'source' => 'column'];
        }

        return new FormulaScope($entries);
    }

    /** API projection: columns with the formula in both forms, its AST (for the client evaluator) and its references. */
    public function present(array $config): array
    {
        $scope = $this->scope($config);
        $calculator = $this->calculator($config);
        $columns = array_map(function (array $c) use ($scope, $calculator, $config) {
            $ast = $calculator->ast($c['key']);
            $dependents = array_values(array_map(fn ($d) => ['key' => $d['key'], 'label' => $d['label']], array_filter($config['columns'], fn ($d) => $d['kind'] === 'formula' && in_array($c['key'], PayrollFormula::references($calculator->ast($d['key'])), true))));

            $default = PayrollTemplate::defaultFormula($c['key']);
            $defaultDisplay = $default === null ? null : PayrollFormula::display(PayrollFormula::parse($default, $scope), $scope);

            return $c + [
                'formula_editable' => $default !== null,
                'template_formula_display' => $defaultDisplay,
                'is_template_default' => $default === null ? null : $c['formula'] === $default,
                'formula_display' => $ast === null ? null : PayrollFormula::display($ast, $scope),
                'ast' => $ast,
                'references' => $ast === null ? [] : PayrollFormula::references($ast),
                'dependents' => $dependents,
                'group_label' => self::GROUPS[$c['group']] ?? $c['group'],
            ];
        }, $config['columns']);

        return [
            'revision' => $config['revision'],
            'settings' => $config['settings'],
            'columns' => $columns,
            'groups' => collect(self::GROUPS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'types' => collect(self::TYPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'limits' => ['max_formula_length' => PayrollFormula::MAX_LENGTH, 'max_nodes' => PayrollFormula::MAX_NODES, 'max_custom_columns' => self::MAX_CUSTOM_COLUMNS, 'functions' => PayrollFormula::FUNCTIONS],
        ];
    }

    // ── validation of a proposed column ───────────────────────────────────

    /**
     * Apply a proposal (create when $key is null, otherwise update) to a copy of the configuration and validate the result.
     *
     * @return array{config: array, key: string}
     */
    public function applyColumn(array $config, ?string $key, array $input): array
    {
        $errors = [];
        $existing = $key === null ? null : collect($config['columns'])->firstWhere('key', $key);
        if ($key !== null && $existing === null) {
            throw new PayrollException('العمود غير موجود.', 'not_found', 404);
        }
        if ($existing === null && count(array_filter($config['columns'], fn ($c) => ! $c['is_system'])) >= self::MAX_CUSTOM_COLUMNS) {
            throw new PayrollException('بلغتَ الحد الأقصى للأعمدة المخصصة ('.self::MAX_CUSTOM_COLUMNS.').', 'payroll_validation', 422, ['label' => ['بلغتَ الحد الأقصى للأعمدة المخصصة.']]);
        }

        $column = $existing ?? [
            'id' => 0, 'key' => 'c_'.bin2hex(random_bytes(5)), 'label' => '', 'group' => 'employee', 'kind' => 'input', 'value_type' => 'amount', 'formula' => null,
            'blank_as_zero' => true, 'allow_negative' => false, 'warn_negative' => false, 'aggregation' => 'none', 'visible_grid' => true,
            'visible_export' => true, 'compact' => false, 'is_system' => false, 'sort_order' => 0,
        ];
        $systemLocked = $existing !== null && $existing['is_system'];
        // A protected template column whose FORMULA (only) the owner may change: the net payable.
        $formulaEditable = $systemLocked && PayrollTemplate::isFormulaEditable($existing['key']);
        if ($systemLocked && ! $formulaEditable && (array_key_exists('formula', $input) || array_key_exists('formula_display', $input))) {
            $errors['formula'][] = 'معادلة هذا العمود من القالب محمية؛ المعادلة القابلة للتعديل هي معادلة «إجمالي الصافي المستحق» فقط.';
        }
        foreach ($input as $field => $value) {
            if (! array_key_exists($field, $column) || in_array($field, ['id', 'key', 'is_system', 'formula'], true)) {
                continue;
            }
            if ($systemLocked && ! in_array($field, self::SYSTEM_EDITABLE, true)) {
                // Silently ignoring would hide that a template column's meaning is protected.
                if ($value != $existing[$field]) {
                    $errors[$field][] = 'عمود من القالب: لا يمكن تغيير هذه الخاصية؛ يمكن تغيير الاسم والترتيب والظهور فقط.';
                }

                continue;
            }
            $column[$field] = $value;
        }

        $label = PayrollText::clean(is_string($column['label']) ? $column['label'] : null);
        if ($label === null) {
            $errors['label'][] = 'اسم العمود مطلوب.';
        } elseif (mb_strlen($label) > 100 || preg_match('/[\[\]{}"]/u', $label) || PayrollText::hasControlCharacters($label)) {
            $errors['label'][] = 'اسم العمود غير صالح (حتى 100 حرف دون الأقواس [ ] { } أو علامات التنصيص).';
        } else {
            $taken = collect($config['columns'])->where('key', '!=', $column['key'])->pluck('label')->merge(collect($config['settings'])->pluck('label'))
                ->contains(fn ($other) => FormulaScope::normalize($other) === FormulaScope::normalize($label));
            if ($taken) {
                $errors['label'][] = 'يوجد عمود أو إعداد بهذا الاسم؛ الأسماء فريدة لأن المعادلات تشير إليها بالاسم.';
            }
            $column['label'] = $label;
        }
        if (! isset(self::GROUPS[$column['group']])) {
            $errors['group'][] = 'مجموعة غير معروفة.';
        }
        if (! isset(self::TYPES[$column['value_type']])) {
            $errors['value_type'][] = 'نوع العمود غير معروف.';
        }
        if (! in_array($column['kind'], ['input', 'formula'], true)) {
            $errors['kind'][] = 'طريقة التعبئة: إدخال يدوي أو معادلة.';
        }
        foreach (['blank_as_zero', 'allow_negative', 'warn_negative', 'visible_grid', 'visible_export', 'compact'] as $flag) {
            $column[$flag] = filter_var($column[$flag], FILTER_VALIDATE_BOOLEAN);
        }
        $column['sort_order'] = max(0, min(65000, (int) $column['sort_order']));

        if ($errors === [] || ! isset($errors['value_type'], $errors['kind'])) {
            if ($column['kind'] === 'formula' && $column['value_type'] === 'text') {
                $errors['value_type'][] = 'العمود المحسوب يعطي رقمًا؛ اختر رقم أو مبلغ أو نسبة.';
            }
            if ($column['value_type'] === 'text' || $column['value_type'] === 'percent') {
                $column['aggregation'] = $column['value_type'] === 'text' ? 'none' : ($column['aggregation'] === 'sum' ? 'sum' : 'none');
                if ($column['value_type'] === 'percent' && $column['aggregation'] === 'sum') {
                    $errors['aggregation'][] = 'لا تُجمع النسب المئوية تلقائيًا.';
                }
            } elseif (! in_array($column['aggregation'], ['sum', 'none'], true)) {
                $errors['aggregation'][] = 'التجميع: مجموع أو بدون.';
            }
        }

        // Kind/type changes must never reinterpret stored values.
        if ($existing !== null && ! $systemLocked) {
            $hasValues = DB::table('payroll_entry_values')->where('payroll_column_id', $existing['id'])->exists();
            $numeric = ['number', 'amount'];
            if ($hasValues && ($existing['kind'] !== $column['kind'] || ($existing['value_type'] !== $column['value_type'] && ! (in_array($existing['value_type'], $numeric, true) && in_array($column['value_type'], $numeric, true))))) {
                $errors['value_type'][] = 'يحتوي العمود على بيانات مدخلة؛ لا يمكن تغيير نوعه أو طريقة تعبئته (يمكن التبديل بين رقم ومبلغ فقط).';
            }
        }

        $config['columns'] = array_values(array_filter($config['columns'], fn ($c) => $c['key'] !== $column['key']));
        $column['formula'] = $existing['formula'] ?? null;
        if ($column['kind'] === 'formula' && (! $systemLocked || $formulaEditable)) {
            $text = $input['formula'] ?? $input['formula_display'] ?? null;
            if ($existing !== null && ! array_key_exists('formula', $input) && ! array_key_exists('formula_display', $input)) {
                $column['formula'] = $existing['formula'];
            } elseif (! is_string($text) || trim($text) === '') {
                $errors['formula'][] = 'المعادلة مطلوبة للعمود المحسوب.';
                $column['formula'] = null;
            } else {
                try {
                    $scopeConfig = $config;
                    $scopeConfig['columns'][] = array_merge($column, ['formula' => '0']); // lets a column name itself so the cycle is reported as a cycle
                    $ast = PayrollFormula::parse($text, $this->scope($scopeConfig));
                    $column['formula'] = PayrollFormula::canonical($ast);
                } catch (FormulaException $e) {
                    $errors['formula'][] = $e->getMessage();
                    $column['formula'] = null;
                }
            }
        } elseif ($column['kind'] === 'input') {
            $column['formula'] = null;
        }
        if ($column['kind'] === 'formula' && $column['formula'] === null && ! isset($errors['formula'])) {
            $errors['formula'][] = 'المعادلة مطلوبة للعمود المحسوب.';
        }

        if ($errors !== []) {
            throw new PayrollException('تعذّر حفظ العمود؛ راجع الحقول المحددة.', 'payroll_validation', 422, $errors);
        }

        $config['columns'][] = $column;
        $this->assertConsistent($config);

        return ['config' => $config, 'key' => $column['key']];
    }

    /**
     * What-if: the saved configuration with a proposed column and/or settings applied (nothing is written).
     *
     * @param  array{column?: array, settings?: array}  $proposal
     */
    public function propose(array $proposal): array
    {
        $config = $this->load();
        if (isset($proposal['settings']) && is_array($proposal['settings']) && $proposal['settings'] !== []) {
            $config = $this->applySettings($config, $proposal['settings']);
        }
        if (isset($proposal['column']) && is_array($proposal['column'])) {
            $key = isset($proposal['column']['key']) && is_string($proposal['column']['key']) ? $proposal['column']['key'] : null;
            $config = $this->applyColumn($config, $key, $proposal['column'])['config'];
        } else {
            $this->assertConsistent($config);
        }

        return $config;
    }

    /** Whole-configuration checks: every formula parses, no cycles, bounded dependency depth. */
    public function assertConsistent(array $config): void
    {
        try {
            $calculator = $this->calculator($config);
        } catch (FormulaException $e) {
            throw new PayrollException($e->getMessage(), 'payroll_validation', 422, ['formula' => [$e->getMessage()]]);
        }
        $depth = [];
        $chain = function (string $key) use (&$chain, &$depth, $calculator): int {
            if (isset($depth[$key])) {
                return $depth[$key];
            }
            $ast = $calculator->ast($key);
            $max = 0;
            foreach ($ast === null ? [] : PayrollFormula::references($ast) as $ref) {
                if ($calculator->ast($ref) !== null) {
                    $max = max($max, $chain($ref));
                }
            }

            return $depth[$key] = $max + 1;
        };
        foreach ($calculator->formulaOrder() as $key) {
            if ($chain($key) > self::MAX_CHAIN) {
                throw new PayrollException('سلسلة المعادلات المتتابعة أطول من الحد المسموح ('.self::MAX_CHAIN.').', 'payroll_validation', 422, ['formula' => ['سلسلة المعادلات المتتابعة أطول من الحد المسموح.']]);
            }
        }
    }

    // ── writing (every method is one transaction guarded by the configuration revision) ──

    private function guarded(int $expectedRevision, int $userId, callable $change): mixed
    {
        return DB::transaction(function () use ($expectedRevision, $userId, $change): mixed {
            $row = DB::table('payroll_config')->where('id', 1)->lockForUpdate()->first();
            if ((int) $row->revision !== $expectedRevision) {
                throw new PayrollException('عُدّلت إعدادات الأعمدة أو المعادلات من جهة أخرى؛ حدّث الصفحة ثم أعد المحاولة. لم يُحفظ شيء.', 'payroll_config_conflict', 409, [], ['config' => $this->present($this->load())]);
            }
            $result = $change($this->load());
            DB::table('payroll_config')->where('id', 1)->update(['revision' => $expectedRevision + 1, 'updated_by_user_id' => $userId, 'updated_at' => now()]);

            return $result;
        });
    }

    public function createColumn(array $input, int $expectedRevision, int $userId): array
    {
        $key = $this->guarded($expectedRevision, $userId, function (array $config) use ($input, $userId): string {
            $applied = $this->applyColumn($config, null, $input);
            $this->persistColumn($applied['config'], $applied['key'], $userId, true);

            return $applied['key'];
        });

        return ['key' => $key, 'config' => $this->present($this->load())];
    }

    public function updateColumn(string $key, array $input, int $expectedRevision, int $userId): array
    {
        $this->guarded($expectedRevision, $userId, function (array $config) use ($key, $input, $userId): void {
            $applied = $this->applyColumn($config, $key, $input);
            $this->persistColumn($applied['config'], $key, $userId, false);
        });

        return ['key' => $key, 'config' => $this->present($this->load())];
    }

    /**
     * Restore a template column's default formula (only columns in PayrollTemplate::EDITABLE_FORMULAS). Settings, employee values and custom
     * columns are not touched; same revision check, atomic transaction and attribution as every other configuration change.
     */
    public function restoreTemplateFormula(string $key, int $expectedRevision, int $userId): array
    {
        $default = PayrollTemplate::defaultFormula($key);
        if ($default === null) {
            throw new PayrollException('هذا العمود لا يملك معادلة قابلة للاستعادة.', 'payroll_validation', 422, ['formula' => ['هذا العمود لا يملك معادلة قابلة للاستعادة.']]);
        }
        $this->guarded($expectedRevision, $userId, function (array $config) use ($key, $default, $userId): void {
            $config['columns'] = array_map(fn ($c) => $c['key'] === $key ? ['formula' => $default] + $c : $c, $config['columns']);
            $this->assertConsistent($config);
            DB::table('payroll_columns')->where('key', $key)->update(['formula' => $default, 'updated_by_user_id' => $userId, 'updated_at' => now()]);
        });

        return ['key' => $key, 'config' => $this->present($this->load())];
    }

    private function persistColumn(array $config, string $key, int $userId, bool $create): void
    {
        $c = collect($config['columns'])->firstWhere('key', $key);
        $row = [
            'label' => $c['label'], 'column_group' => $c['group'], 'kind' => $c['kind'], 'value_type' => $c['value_type'], 'formula' => $c['formula'],
            'blank_as_zero' => $c['blank_as_zero'], 'allow_negative' => $c['allow_negative'], 'warn_negative' => $c['warn_negative'],
            'aggregation' => $c['aggregation'], 'visible_grid' => $c['visible_grid'], 'visible_export' => $c['visible_export'], 'compact' => $c['compact'],
            'sort_order' => $c['sort_order'], 'updated_by_user_id' => $userId, 'updated_at' => now(),
        ];
        if ($create) {
            $max = (int) DB::table('payroll_columns')->where('column_group', $c['group'])->max('sort_order');
            DB::table('payroll_columns')->insert($row + ['key' => $key, 'is_system' => false, 'created_by_user_id' => $userId, 'created_at' => now()]);
            if ((int) $c['sort_order'] === 0) {
                DB::table('payroll_columns')->where('key', $key)->update(['sort_order' => $max + 10]);
            }
        } else {
            DB::table('payroll_columns')->where('key', $key)->update($row);
        }
    }

    /** Custom columns only; refused while a formula references the column; stored values need explicit confirmation. */
    public function deleteColumn(string $key, bool $confirmValues, int $expectedRevision, int $userId): array
    {
        $this->guarded($expectedRevision, $userId, function (array $config) use ($key, $confirmValues): void {
            $column = collect($config['columns'])->firstWhere('key', $key);
            if ($column === null) {
                throw new PayrollException('العمود غير موجود.', 'not_found', 404);
            }
            if ($column['is_system']) {
                throw new PayrollException('أعمدة القالب لا تُحذف؛ يمكن إخفاؤها.', 'payroll_column_protected', 409);
            }
            $calculator = $this->calculator($config);
            $dependents = array_values(array_map(fn ($d) => ['key' => $d['key'], 'label' => $d['label']], array_filter($config['columns'], fn ($d) => $d['kind'] === 'formula' && in_array($key, PayrollFormula::references($calculator->ast($d['key'])), true))));
            if ($dependents !== []) {
                throw new PayrollException('لا يمكن حذف «'.$column['label'].'» لأن معادلات تعتمد عليه: '.implode('، ', array_column($dependents, 'label')).'. عدّل هذه المعادلات أولًا.', 'payroll_column_has_dependents', 409, [], ['dependents' => $dependents]);
            }
            $values = DB::table('payroll_entry_values')->where('payroll_column_id', $column['id'])->count();
            if ($values > 0 && ! $confirmValues) {
                throw new PayrollException("يحتوي العمود على {$values} قيمة مدخلة ستُحذف نهائيًا مع العمود؛ أكّد الحذف.", 'payroll_column_has_values', 409, [], ['values_count' => $values]);
            }
            DB::table('payroll_entry_values')->where('payroll_column_id', $column['id'])->delete();
            DB::table('payroll_columns')->where('id', $column['id'])->delete();
        });

        return ['config' => $this->present($this->load())];
    }

    /**
     * Reorder / show / hide in one atomic save. `columns` must list every column exactly once, in the wanted order.
     *
     * @param  list<array{key:string, visible_grid?:bool, visible_export?:bool, compact?:bool, group?:string}>  $layout
     */
    public function saveLayout(array $layout, int $expectedRevision, int $userId): array
    {
        $this->guarded($expectedRevision, $userId, function (array $config) use ($layout, $userId): void {
            $keys = array_column($layout, 'key');
            $known = array_column($config['columns'], 'key');
            if (count($keys) !== count(array_unique($keys)) || array_diff($keys, $known) || array_diff($known, $keys)) {
                throw new PayrollException('قائمة الأعمدة المرسلة لا تطابق الأعمدة الحالية؛ حدّث الصفحة.', 'payroll_validation', 422, ['columns' => ['يجب إرسال كل الأعمدة مرة واحدة.']]);
            }
            $byKey = collect($config['columns'])->keyBy('key');
            foreach ($layout as $i => $item) {
                $current = $byKey[$item['key']];
                $group = $item['group'] ?? $current['group'];
                if (! isset(self::GROUPS[$group]) || ($current['is_system'] && $group !== $current['group'])) {
                    throw new PayrollException('مجموعة غير صالحة لعمود «'.$current['label'].'».', 'payroll_validation', 422, ['columns' => ['مجموعة غير صالحة.']]);
                }
                DB::table('payroll_columns')->where('key', $item['key'])->update([
                    'sort_order' => ($i + 1) * 10, 'column_group' => $group, 'updated_by_user_id' => $userId, 'updated_at' => now(),
                    'visible_grid' => filter_var($item['visible_grid'] ?? $current['visible_grid'], FILTER_VALIDATE_BOOLEAN),
                    'visible_export' => filter_var($item['visible_export'] ?? $current['visible_export'], FILTER_VALIDATE_BOOLEAN),
                    'compact' => filter_var($item['compact'] ?? $current['compact'], FILTER_VALIDATE_BOOLEAN),
                ]);
            }
        });

        return ['config' => $this->present($this->load())];
    }

    /** @param array<string, mixed> $values setting key => decimal string (a percentage as a fraction: 0.07) */
    public function applySettings(array $config, array $values): array
    {
        $errors = [];
        foreach ($values as $key => $raw) {
            $index = collect($config['settings'])->search(fn ($s) => $s['key'] === $key);
            if ($index === false) {
                $errors["settings.{$key}"][] = 'إعداد غير معروف.';

                continue;
            }
            $setting = $config['settings'][$index];
            try {
                $v = self::parseSetting($raw, $setting['value_type']);
            } catch (\InvalidArgumentException $e) {
                $errors["settings.{$key}"][] = $e->getMessage();

                continue;
            }
            $config['settings'][$index]['value'] = PayrollCalculator::plain($v);
        }
        if ($errors !== []) {
            throw new PayrollException('قيمة إعداد غير صالحة؛ لم يُحفظ شيء.', 'payroll_validation', 422, $errors);
        }

        return $config;
    }

    public static function parseSetting(mixed $raw, string $type): BigDecimal
    {
        if (! is_string($raw) && ! is_int($raw)) {
            throw new \InvalidArgumentException('أرسل القيمة كنص عشري.');
        }
        $text = trim((string) $raw);
        if (! preg_match('/^\d{1,9}(\.\d{1,6})?$/', $text)) {
            throw new \InvalidArgumentException('قيمة غير صالحة؛ أدخل رقمًا غير سالب.');
        }
        try {
            $v = BigDecimal::of($text);
        } catch (MathException) {
            throw new \InvalidArgumentException('قيمة غير صالحة.');
        }
        if ($type === 'percent' && $v->isGreaterThan(1)) {
            throw new \InvalidArgumentException('النسبة بين 0% و100%.');
        }
        if ($type === 'amount' && ($v->scale() > 2 && ! $v->toScale(2, RoundingMode::DOWN)->isEqualTo($v))) {
            throw new \InvalidArgumentException('المبلغ بحد أقصى منزلتين عشريتين.');
        }

        return $v;
    }

    public function updateSettings(array $values, int $expectedRevision, int $userId): array
    {
        $this->guarded($expectedRevision, $userId, function (array $config) use ($values, $userId): void {
            $next = $this->applySettings($config, $values);
            foreach ($next['settings'] as $setting) {
                DB::table('payroll_settings')->where('key', $setting['key'])->update([
                    'value_scaled' => PayrollCalculator::toStored(BigDecimal::of($setting['value'])), 'updated_by_user_id' => $userId, 'updated_at' => now(),
                ]);
            }
        });

        return ['config' => $this->present($this->load())];
    }
}
