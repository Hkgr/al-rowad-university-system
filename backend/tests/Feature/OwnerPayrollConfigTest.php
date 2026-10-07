<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * Configurable columns, formulas and settings: validation, evaluation, dependencies, protection and conflicts.
 */
final class OwnerPayrollConfigTest extends OwnerPayrollTestCase
{
    private const CFG = self::API.'/payroll/config';

    private function column(array $fields, ?int $revision = null)
    {
        return $this->postJson(self::CFG.'/columns', $fields + ['config_revision' => $revision ?? $this->configRevision()]);
    }

    private function seeded(): array
    {
        $this->actingAsUser(self::OWNER);
        $row = $this->employee(['employee_number' => '0001']);

        return $this->values([$this->change($row, ['fixed_salary' => '96600', 'compensation' => '55200'])])->assertOk()->json('data.0');
    }

    public function test_custom_columns_of_every_type_store_values_and_a_plain_column_never_changes_net_payable(): void
    {
        $row = $this->seeded();
        $net = $row['cells']['total_net_payable']['v'];
        $text = $this->column(['label' => 'ملاحظات', 'kind' => 'input', 'value_type' => 'text', 'group' => 'employee'])->assertCreated()->json('data.key');
        $amount = $this->column(['label' => 'بدل نقل', 'kind' => 'input', 'value_type' => 'amount', 'group' => 'salary', 'aggregation' => 'sum'])->assertCreated()->json('data.key');
        $number = $this->column(['label' => 'أيام الدوام', 'kind' => 'input', 'value_type' => 'number', 'group' => 'employee'])->assertCreated()->json('data.key');
        $percent = $this->column(['label' => 'نسبة الحضور', 'kind' => 'input', 'value_type' => 'percent', 'group' => 'employee'])->assertCreated()->json('data.key');
        $this->assertMatchesRegularExpression('/^c_[0-9a-f]{10}$/', $amount);

        $r = $this->values([$this->change($row, [$text => '=1+1 ملاحظة', $amount => '1500.55', $number => '22.5', $percent => '0.85'])])->assertOk()->json('data.0');
        $this->assertSame('=1+1 ملاحظة', $r['cells'][$text]['v']);
        $this->assertSame('1500.55', $r['cells'][$amount]['v']);
        $this->assertSame('22.5', $r['cells'][$number]['v']);
        $this->assertSame('0.85', $r['cells'][$percent]['v']);
        $this->assertSame($net, $r['cells']['total_net_payable']['v'], 'adding a custom column alone must not change net payable');
        $totals = $this->getJson(self::API.'/payroll/sheet')->json('meta.totals.columns');
        $this->assertSame('1500.55', $totals[$amount]['sum']);
        $this->assertArrayNotHasKey($percent, $totals, 'percentages are never auto-summed');
        $this->column(['label' => 'نسبة مجموعة', 'kind' => 'input', 'value_type' => 'percent', 'aggregation' => 'sum'])->assertUnprocessable()->assertJsonStructure(['errors' => ['aggregation']]);
        $this->values([$this->change($r, [$percent => '85%'])])->assertUnprocessable();
    }

    public function test_formula_columns_evaluate_with_names_settings_functions_and_resolve_to_stable_ids(): void
    {
        $row = $this->seeded();
        $data = $this->column([
            'label' => 'تأمين مضاعف', 'kind' => 'formula', 'value_type' => 'amount', 'group' => 'deductions', 'aggregation' => 'sum',
            'formula' => '=[الأجر المقطوع] * [نسبة التأمينات] * 2',
        ])->assertCreated()->json('data');
        $col = collect($data['config']['columns'])->firstWhere('key', $data['key']);
        $this->assertSame('{fixed_salary} * {insurance_rate} * 2', $col['formula']);
        $this->assertSame('[الأجر المقطوع] * [نسبة التأمينات] * 2', $col['formula_display']);
        $this->assertSame('13524.00', $this->sheetRow('0001')['cells'][$data['key']]['v']);

        $cond = $this->column([
            'label' => 'مكافأة شرطية', 'kind' => 'formula', 'value_type' => 'amount', 'group' => 'salary',
            'formula' => 'IF([الأجر المقطوع] > 50000, MAX([التعويض] * 10%, 1000), MIN(500, ROUND([الأجر المقطوع] / 3, 0)))',
        ])->assertCreated()->json('data.key');
        $this->assertSame([ 'v' => '5520.00', 'st' => null, 'm' => null], $this->sheetRow('0001')['cells'][$cond]);

        // Rename never breaks the formula (stable ids) and the display follows the new name.
        $rename = $this->patchJson(self::CFG.'/columns/fixed_salary', ['label' => 'الراتب الأساسي المقطوع', 'config_revision' => $this->configRevision()])->assertOk()->json('data.config');
        $this->assertSame('[الراتب الأساسي المقطوع] * [نسبة التأمينات] * 2', collect($rename['columns'])->firstWhere('key', $data['key'])['formula_display']);
        $this->assertSame('13524.00', $this->sheetRow('0001')['cells'][$data['key']]['v']);
        $this->assertSame('125166.30', $this->sheetRow('0001')['cells']['total_net_payable']['v']);
    }

    public function test_invalid_formulas_are_rejected_with_a_reason_and_nothing_is_saved(): void
    {
        $this->seeded();
        $revision = $this->configRevision();
        foreach ([
            '[غير موجود] + 1', 'SUM(', '1 +', 'POWER(2, 3)', '[الأجر المقطوع] ; 1', 'eval(1)', '1 / ', '"نص" + 1', 'IF(1, 2)', '[ملاحظات] * 2',
            str_repeat('1+', 400).'1', 'ROUND(1)', '((((((((((((((((((((((((((1))))))))))))))))))))))))))', '1 2', '@cmd', 'A1 + 1',
        ] as $bad) {
            $this->column(['label' => 'اختبار', 'kind' => 'formula', 'value_type' => 'amount', 'formula' => $bad], $revision)
                ->assertUnprocessable()->assertJsonStructure(['errors' => ['formula']]);
        }
        $this->assertSame($revision, $this->configRevision());
        $this->assertSame(17, DB::table('payroll_columns')->count());
        // A calculated column cannot yield text, and a formula column needs a formula.
        $this->column(['label' => 'نصي', 'kind' => 'formula', 'value_type' => 'text', 'formula' => '1'])->assertUnprocessable()->assertJsonStructure(['errors' => ['value_type']]);
        $this->column(['label' => 'بلا معادلة', 'kind' => 'formula', 'value_type' => 'amount'])->assertUnprocessable()->assertJsonStructure(['errors' => ['formula']]);
        // Duplicate or reserved names are refused (formulas refer to columns by name).
        $this->column(['label' => 'الأجر المقطوع', 'kind' => 'input', 'value_type' => 'amount'])->assertUnprocessable()->assertJsonStructure(['errors' => ['label']]);
        $this->column(['label' => 'نسبة التأمينات', 'kind' => 'input', 'value_type' => 'amount'])->assertUnprocessable()->assertJsonStructure(['errors' => ['label']]);
        $this->column(['label' => 'اسم [بأقواس]', 'kind' => 'input', 'value_type' => 'amount'])->assertUnprocessable();
    }

    public function test_circular_references_and_self_references_are_rejected(): void
    {
        $this->seeded();
        $a = $this->column(['label' => 'العمود أ', 'kind' => 'formula', 'value_type' => 'amount', 'formula' => '[الأجر المقطوع] + 1'])->assertCreated()->json('data.key');
        $b = $this->column(['label' => 'العمود ب', 'kind' => 'formula', 'value_type' => 'amount', 'formula' => '[العمود أ] + 1'])->assertCreated()->json('data.key');
        $this->patchJson(self::CFG."/columns/{$a}", ['formula' => '[العمود ب] + 1', 'config_revision' => $this->configRevision()])
            ->assertUnprocessable()->assertJsonPath('errors.formula.0', fn ($m) => str_contains($m, 'دائرية'));
        $this->patchJson(self::CFG."/columns/{$a}", ['formula' => '[العمود أ] + 1', 'config_revision' => $this->configRevision()])->assertUnprocessable();
        // The saved formulas are untouched and still evaluate.
        $this->assertSame('96602.00', $this->sheetRow('0001')['cells'][$b]['v']);
    }

    public function test_division_by_zero_and_missing_inputs_show_at_the_affected_cell_only(): void
    {
        $this->seeded();
        $second = $this->employee(['employee_number' => '0002']);
        $days = $this->column(['label' => 'أيام', 'kind' => 'input', 'value_type' => 'number', 'blank_as_zero' => true])->assertCreated()->json('data.key');
        $daily = $this->column(['label' => 'الأجر اليومي', 'kind' => 'formula', 'value_type' => 'amount', 'formula' => '[الأجر المقطوع] / [أيام]'])->assertCreated()->json('data.key');
        $first = $this->sheetRow('0001');
        $this->values([$this->change($first, [$days => '30'])])->assertOk();
        $one = $this->sheetRow('0001');
        $this->assertSame('3220.00', $one['cells'][$daily]['v']);

        $this->values([$this->change($one, [$days => '0'])])->assertOk();
        $err = $this->sheetRow('0001')['cells'][$daily];
        $this->assertSame('error', $err['st']);
        $this->assertNull($err['v']);
        $this->assertStringContainsString('صفر', $err['m']);
        $this->assertSame('125166.30', $this->sheetRow('0001')['cells']['total_net_payable']['v'], 'an error in one column never zeroes or alters unrelated columns');
        // Missing required input: the second employee has no fixed salary.
        $this->assertSame('missing', $this->sheetRow('0002')['cells'][$daily]['st']);
        // Totals exclude and count unavailable cells instead of treating them as zero.
        $totals = $this->getJson(self::API.'/payroll/sheet')->json('meta.totals');
        $this->assertSame(1, $totals['columns']['total_net_payable']['excluded']);
        $this->assertSame(1, $totals['incomplete']);
        $this->assertSame($second['id'], $this->getJson(self::API.'/home')->json('data.attention.items.0.employee_id'));
    }

    public function test_deleting_a_referenced_column_is_refused_and_values_need_confirmation(): void
    {
        $this->seeded();
        $bonus = $this->column(['label' => 'مكافأة', 'kind' => 'input', 'value_type' => 'amount'])->assertCreated()->json('data.key');
        $f = $this->column(['label' => 'مجموع المكافأة', 'kind' => 'formula', 'value_type' => 'amount', 'formula' => '[مكافأة] * 2'])->assertCreated()->json('data.key');
        $cfg = $this->getJson(self::CFG)->json('data');
        $this->assertSame([$f], array_column(collect($cfg['columns'])->firstWhere('key', $bonus)['dependents'], 'key'));

        $this->deleteJson(self::CFG."/columns/{$bonus}", ['config_revision' => $this->configRevision()])
            ->assertStatus(409)->assertJsonPath('error_code', 'payroll_column_has_dependents')->assertJsonPath('data.dependents.0.key', $f);
        $this->deleteJson(self::CFG."/columns/fixed_salary", ['config_revision' => $this->configRevision()])->assertStatus(409)->assertJsonPath('error_code', 'payroll_column_protected');

        $this->deleteJson(self::CFG."/columns/{$f}", ['config_revision' => $this->configRevision()])->assertOk();
        $row = $this->sheetRow('0001');
        $this->values([$this->change($row, [$bonus => '10'])])->assertOk();
        $this->deleteJson(self::CFG."/columns/{$bonus}", ['config_revision' => $this->configRevision()])->assertStatus(409)->assertJsonPath('error_code', 'payroll_column_has_values');
        $this->assertSame(1, DB::table('payroll_entry_values')->where('payroll_column_id', DB::table('payroll_columns')->where('key', $bonus)->value('id'))->count());
        $this->deleteJson(self::CFG."/columns/{$bonus}", ['config_revision' => $this->configRevision(), 'confirm_values' => true])->assertOk();
        $this->assertSame(0, DB::table('payroll_columns')->where('key', $bonus)->count());
    }

    public function test_calculated_cells_are_read_only_and_type_changes_never_reinterpret_stored_values(): void
    {
        $row = $this->seeded();
        $this->values([$this->change($row, ['total_net_payable' => '1'])])->assertUnprocessable()->assertJsonStructure(['errors' => ['changes.0.values.total_net_payable']]);
        $this->values([$this->change($row, ['nonexistent' => '1'])])->assertUnprocessable();
        $this->assertSame('125166.30', $this->sheetRow('0001')['cells']['total_net_payable']['v']);

        // Template columns keep their meaning: only label/visibility/order change.
        $this->patchJson(self::CFG.'/columns/insurance', ['formula' => '1', 'config_revision' => $this->configRevision()])->assertOk();
        $this->assertSame('6762.00', $this->sheetRow('0001')['cells']['insurance']['v'], 'a template formula cannot be replaced');
        $this->patchJson(self::CFG.'/columns/fixed_salary', ['value_type' => 'text', 'config_revision' => $this->configRevision()])->assertUnprocessable();
        $this->patchJson(self::CFG.'/columns/insurance', ['visible_grid' => false, 'visible_export' => false, 'config_revision' => $this->configRevision()])->assertOk();
        $this->assertFalse(collect($this->getJson(self::CFG)->json('data.columns'))->firstWhere('key', 'insurance')['visible_grid']);

        $amount = $this->column(['label' => 'مبلغ حر', 'kind' => 'input', 'value_type' => 'amount'])->assertCreated()->json('data.key');
        $r = $this->values([$this->change($this->sheetRow('0001'), [$amount => '5'])])->assertOk()->json('data.0');
        $this->patchJson(self::CFG."/columns/{$amount}", ['value_type' => 'text', 'config_revision' => $this->configRevision()])->assertUnprocessable();
        $this->patchJson(self::CFG."/columns/{$amount}", ['value_type' => 'number', 'config_revision' => $this->configRevision()])->assertOk();
        $this->assertSame('5', $this->sheetRow('0001')['cells'][$amount]['v']);
    }

    public function test_layout_changes_reorder_and_hide_without_moving_values_and_are_all_or_nothing(): void
    {
        $this->seeded();
        $cfg = $this->getJson(self::CFG)->json('data');
        $layout = collect($cfg['columns'])->map(fn ($c) => ['key' => $c['key']])->reverse()->values()->all();
        $before = $this->sheetRow('0001')['cells'];
        $this->putJson(self::CFG.'/layout', ['config_revision' => $cfg['revision'], 'columns' => array_slice($layout, 1)])->assertUnprocessable();
        $this->putJson(self::CFG.'/layout', ['config_revision' => $cfg['revision'], 'columns' => $layout])->assertOk();
        $after = $this->getJson(self::CFG)->json('data');
        $this->assertSame($cfg['revision'] + 1, $after['revision']);
        $keysOf = fn (string $group) => array_column(array_filter($after['columns'], fn ($c) => $c['group'] === $group), 'key');
        $this->assertSame(array_values(array_filter(array_column($layout, 'key'), fn ($k) => in_array($k, $keysOf('salary'), true))), $keysOf('salary'), 'new order applies within a group');
        $now = $this->sheetRow('0001')['cells'];
        ksort($before);
        ksort($now);
        $this->assertSame($before, $now, 'reordering never moves or changes a value');
        // Stale layout save.
        $this->putJson(self::CFG.'/layout', ['config_revision' => $cfg['revision'], 'columns' => $layout])->assertStatus(409)->assertJsonPath('error_code', 'payroll_config_conflict');
    }

    public function test_impact_preview_reports_before_and_after_for_a_proposed_column_without_saving(): void
    {
        $row = $this->seeded();
        $revision = $this->configRevision();
        $r = $this->postJson(self::CFG.'/preview', [
            'column' => ['label' => 'اختبار', 'kind' => 'formula', 'value_type' => 'amount', 'group' => 'salary', 'formula' => '[الأجر المقطوع] * 2'], 'employee_id' => $row['id'],
        ])->assertOk()->json('data');
        $this->assertSame(1, count($r['preview']['cells']));
        $this->assertSame('193200.00', array_values($r['preview']['cells'])[0]['v']);
        $this->assertSame(0, $r['employees_changed']);
        $this->assertSame($revision, $this->configRevision());
        $this->assertSame(17, DB::table('payroll_columns')->count());
        $this->postJson(self::CFG.'/preview', ['column' => ['label' => 'x', 'kind' => 'formula', 'value_type' => 'amount', 'formula' => '[أ ب ج]']])->assertUnprocessable();
    }

    public function test_column_management_is_a_separate_permission(): void
    {
        $this->actingAsUser(self::OWNER);
        $permission = DB::table('permissions')->where('permission_code', 'owner_payroll.config.manage')->value('permission_id');
        DB::table('role_permissions')->where('role_id', 2)->where('permission_id', $permission)->delete();
        $this->getJson(self::CFG)->assertOk();
        $this->column(['label' => 'x', 'kind' => 'input', 'value_type' => 'amount'])->assertForbidden();
        $this->patchJson(self::CFG.'/settings', ['config_revision' => 1, 'settings' => []])->assertForbidden();
        $this->postJson(self::CFG.'/preview', [])->assertForbidden();
    }
}
