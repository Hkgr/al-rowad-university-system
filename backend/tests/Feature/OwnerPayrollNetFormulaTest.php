<?php

namespace Tests\Feature;

use App\Services\Payroll\PayrollTemplate;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecalculatesWorkbooks;

/**
 * The authoritative net payable (`total_net_payable`) has an owner-editable formula that can include custom columns,
 * with a "restore the template formula" action. Everything that reports net payable follows the saved formula.
 */
final class OwnerPayrollNetFormulaTest extends OwnerPayrollTestCase
{
    use RecalculatesWorkbooks;

    private const CFG = self::API.'/payroll/config';

    private const EDITED = '[صافي الراتب] + [صافي التعويض] - [حسميات أخرى] + [مكافأة إضافية] - [حسم إضافي]';

    private function column(array $fields)
    {
        return $this->postJson(self::CFG.'/columns', $fields + ['config_revision' => $this->configRevision()]);
    }

    private function net(array $overrides = [])
    {
        return $this->patchJson(self::CFG.'/columns/total_net_payable', $overrides + ['config_revision' => $this->configRevision()]);
    }

    /** The reference employee plus the two custom inputs (1,000 and 250). */
    private function referenceWithCustomColumns(): array
    {
        $this->actingAsUser(self::OWNER);
        $bonus = $this->column(['label' => 'مكافأة إضافية', 'kind' => 'input', 'value_type' => 'amount', 'group' => 'net', 'aggregation' => 'sum'])->assertCreated()->json('data.key');
        $extra = $this->column(['label' => 'حسم إضافي', 'kind' => 'input', 'value_type' => 'amount', 'group' => 'net', 'aggregation' => 'sum'])->assertCreated()->json('data.key');
        $row = $this->employee(['employee_number' => '0001']);
        $this->values([$this->change($row, [
            'fixed_salary' => '96600', 'compensation' => '55200', 'salary_adjustment' => '0', 'compensation_adjustment' => '0', 'other_deductions' => '0', $bonus => '1000', $extra => '250',
        ])])->assertOk();

        return [$bonus, $extra];
    }

    public function test_the_default_is_defined_once_and_matches_the_seeded_template(): void
    {
        $this->actingAsUser(self::OWNER);
        $this->assertSame(PayrollTemplate::NET_PAYABLE_FORMULA, DB::table('payroll_columns')->where('key', 'total_net_payable')->value('formula'));
        $net = collect($this->getJson(self::CFG)->json('data.columns'))->firstWhere('key', 'total_net_payable');
        $this->assertTrue($net['formula_editable']);
        $this->assertTrue($net['is_template_default']);
        $this->assertSame('[صافي الراتب] + [صافي التعويض] - [حسميات أخرى]', $net['template_formula_display']);
        $others = collect($this->getJson(self::CFG)->json('data.columns'))->where('is_system', true)->where('key', '!=', 'total_net_payable')->pluck('formula_editable')->unique()->all();
        $this->assertSame([false], $others, 'no other template column has an editable formula');
    }

    public function test_adding_custom_columns_alone_leaves_net_payable_unchanged_until_the_formula_references_them(): void
    {
        $this->referenceWithCustomColumns();
        $this->assertSame('125166.30', $this->sheetRow('0001')['cells']['total_net_payable']['v']);
    }

    public function test_editing_the_final_formula_changes_the_authoritative_net_payable_everywhere(): void
    {
        $this->referenceWithCustomColumns();
        $body = DB::table('payroll_bodies')->value('id');
        $second = $this->employee(['employee_number' => '0002']); // fixed salary blank => net unavailable, not zero
        $revision = $this->configRevision();

        // Impact preview first: nothing is saved by it.
        $preview = $this->postJson(self::CFG.'/preview', ['column' => ['key' => 'total_net_payable', 'formula' => self::EDITED], 'employee_id' => $this->sheetRow('0001')['id']])->assertOk()->json('data');
        $this->assertSame('125916.30', $preview['preview']['cells']['total_net_payable']['v']);
        $this->assertSame('125166.30', $preview['preview']['before']['total_net_payable']['v']);
        $this->assertSame(['sum' => '125166.30', 'excluded' => 1], $preview['net_payable']['before']);
        $this->assertSame(['sum' => '125916.30', 'excluded' => 1], $preview['net_payable']['after']);
        $this->assertSame(1, $preview['employees_changed']);
        $this->assertSame($revision, $this->configRevision());

        $saved = $this->net(['formula' => self::EDITED])->assertOk()->json('data.config');
        $net = collect($saved['columns'])->firstWhere('key', 'total_net_payable');
        $this->assertSame(self::EDITED, $net['formula_display']);
        $this->assertFalse($net['is_template_default']);
        $this->assertSame($revision + 1, $this->configRevision());
        $this->assertSame(self::OWNER, (int) DB::table('payroll_config')->value('updated_by_user_id'));
        $this->assertSame(self::OWNER, (int) DB::table('payroll_columns')->where('key', 'total_net_payable')->value('updated_by_user_id'));

        $row = $this->sheetRow('0001');
        $this->assertSame('125916.30', $row['cells']['total_net_payable']['v'], 'authoritative net payable includes +1,000 and −250');
        $this->assertSame('26633.70', $row['cells']['total_deductions']['v'], 'other breakdown figures keep their own definitions (the custom amount is not silently a deduction)');
        $this->assertSame('151800.00', $row['cells']['gross_entitlement']['v']);
        $this->assertSame('46920.00', $row['cells']['net_compensation']['v']);

        // Grid totals, Home, summaries, completeness and sorting follow the saved formula.
        $totals = $this->getJson(self::API.'/payroll/sheet')->json('meta.totals');
        $this->assertSame('125916.30', $totals['columns']['total_net_payable']['sum']);
        $home = $this->getJson(self::API.'/home')->assertOk()->json('data');
        $this->assertSame('125916.30', $home['totals']['net_payable']);
        $this->assertSame('125916.30', collect($home['by_body'])->firstWhere('value', (string) $body)['net_payable']);
        $this->assertSame('125916.30', collect($home['by_workplace'])->firstWhere('value', 'afrin')['net_payable']);
        $this->assertSame('26633.70', $home['totals']['total_deductions']);
        $this->assertSame(1, $home['incomplete']);
        $this->assertSame($second['id'], $home['attention']['items'][0]['employee_id']);
        $this->assertSame(['0001', '0002'], array_column($this->getJson(self::API.'/payroll/sheet?sort=total_net_payable&direction=desc')->json('data'), 'employee_number'));
    }

    public function test_a_formula_that_becomes_unavailable_or_errors_is_never_zero(): void
    {
        $this->referenceWithCustomColumns();
        $this->column(['label' => 'مقسوم', 'kind' => 'input', 'value_type' => 'number', 'blank_as_zero' => true])->assertCreated();
        $this->net(['formula' => '[صافي الراتب] / [مقسوم]'])->assertOk();
        $cell = $this->sheetRow('0001')['cells']['total_net_payable'];
        $this->assertSame('error', $cell['st']);
        $this->assertNull($cell['v']);
        $this->assertSame(1, $this->getJson(self::API.'/home')->json('data.incomplete'));
    }

    public function test_restoring_the_template_formula_changes_only_that_formula(): void
    {
        [$bonus] = $this->referenceWithCustomColumns();
        $this->net(['formula' => self::EDITED])->assertOk();
        $this->patchJson(self::CFG.'/settings', ['config_revision' => $this->configRevision(), 'settings' => ['insurance_rate' => '0.08']])->assertOk();
        $before = [
            'values' => DB::table('payroll_entry_values')->count(), 'columns' => DB::table('payroll_columns')->where('is_system', false)->count(),
            'rate' => collect($this->getJson(self::CFG)->json('data.settings'))->firstWhere('key', 'insurance_rate')['value'],
        ];
        $revision = $this->configRevision();

        $this->postJson(self::CFG.'/columns/total_net_payable/restore-formula', ['config_revision' => $revision - 1])->assertStatus(409)->assertJsonPath('error_code', 'payroll_config_conflict');
        $restored = $this->postJson(self::CFG.'/columns/total_net_payable/restore-formula', ['config_revision' => $revision])->assertOk()->json('data.config');

        $this->assertSame(PayrollTemplate::NET_PAYABLE_FORMULA, DB::table('payroll_columns')->where('key', 'total_net_payable')->value('formula'));
        $this->assertTrue(collect($restored['columns'])->firstWhere('key', 'total_net_payable')['is_template_default']);
        $this->assertSame($revision + 1, $this->configRevision());
        $this->assertSame($before, [
            'values' => DB::table('payroll_entry_values')->count(), 'columns' => DB::table('payroll_columns')->where('is_system', false)->count(),
            'rate' => collect($this->getJson(self::CFG)->json('data.settings'))->firstWhere('key', 'insurance_rate')['value'],
        ], 'settings, employee values and custom columns are untouched');
        $this->assertSame('1000.00', $this->sheetRow('0001')['cells'][$bonus]['v']);
        // Insurance 8 % was kept: 7,728.00; net payable is the template result again (the custom amounts no longer count).
        $this->assertSame('7728.00', $this->sheetRow('0001')['cells']['insurance']['v']);
        $this->assertFalse(str_contains(DB::table('payroll_columns')->where('key', 'total_net_payable')->value('formula'), 'c_'));
        $this->postJson(self::CFG.'/columns/insurance/restore-formula', ['config_revision' => $this->configRevision()])->assertUnprocessable();
        $this->postJson(self::CFG.'/columns/'.$bonus.'/restore-formula', ['config_revision' => $this->configRevision()])->assertUnprocessable();
    }

    public function test_the_net_payable_stays_protected_apart_from_its_formula(): void
    {
        $this->referenceWithCustomColumns();
        foreach ([['kind' => 'input'], ['value_type' => 'text'], ['value_type' => 'percent'], ['aggregation' => 'none'], ['group' => 'salary'], ['blank_as_zero' => true]] as $change) {
            $this->net($change)->assertUnprocessable();
        }
        $this->net(['key' => 'other', 'formula' => self::EDITED])->assertOk();
        $this->assertSame(1, DB::table('payroll_columns')->where('key', 'total_net_payable')->count(), 'its key is stable');
        $this->deleteJson(self::CFG.'/columns/total_net_payable', ['config_revision' => $this->configRevision()])->assertStatus(409)->assertJsonPath('error_code', 'payroll_column_protected');
        $row = $this->sheetRow('0001');
        $this->values([$this->change($row, ['total_net_payable' => '5'])])->assertUnprocessable()->assertJsonStructure(['errors' => ['changes.0.values.total_net_payable']]);
        // Label and visibility remain editable like every template column; the result stays an amount with its aggregation.
        $net = collect($this->net(['label' => 'الصافي النهائي', 'visible_export' => true])->assertOk()->json('data.config.columns'))->firstWhere('key', 'total_net_payable');
        $this->assertSame(['amount', 'sum', 'formula'], [$net['value_type'], $net['aggregation'], $net['kind']]);
        $this->assertSame(self::EDITED, $net['formula_display']);
    }

    public function test_invalid_circular_and_ill_typed_final_formulas_are_rejected_and_nothing_is_saved(): void
    {
        $this->referenceWithCustomColumns();
        $revision = $this->configRevision();
        foreach ([
            '[إجمالي الصافي المستحق] + 1' => 'دائرية',
            '[غير موجود] + 1' => 'غير',
            '[صافي الراتب] +' => '',
            'POWER(2; 3)' => '',
            '' => '',
        ] as $bad => $needle) {
            $response = $this->net(['formula' => $bad])->assertUnprocessable()->assertJsonStructure(['errors' => ['formula']]);
            if ($needle !== '') {
                $this->assertStringContainsString($needle, $response->json('errors.formula.0'));
            }
        }
        // A custom column that uses the net payable while the net payable uses it is a cycle too.
        $loop = $this->column(['label' => 'يعتمد على الصافي', 'kind' => 'formula', 'value_type' => 'amount', 'formula' => '[إجمالي الصافي المستحق] * 2'])->assertCreated()->json('data.key');
        $this->net(['formula' => '[صافي الراتب] + [يعتمد على الصافي]'])->assertUnprocessable()->assertJsonPath('errors.formula.0', fn ($m) => str_contains($m, 'دائرية'));
        $this->assertSame($revision + 1, $this->configRevision(), 'only the valid custom column was saved');
        $this->assertSame(PayrollTemplate::NET_PAYABLE_FORMULA, DB::table('payroll_columns')->where('key', 'total_net_payable')->value('formula'));
        $this->assertNotNull($loop);
    }

    public function test_a_custom_column_used_by_the_final_formula_cannot_be_deleted_until_the_formula_is_changed(): void
    {
        [$bonus] = $this->referenceWithCustomColumns();
        $this->net(['formula' => self::EDITED])->assertOk();
        $this->deleteJson(self::CFG.'/columns/'.$bonus, ['config_revision' => $this->configRevision(), 'confirm_values' => true])
            ->assertStatus(409)->assertJsonPath('error_code', 'payroll_column_has_dependents')->assertJsonPath('data.dependents.0.key', 'total_net_payable');
        $present = collect($this->getJson(self::CFG)->json('data.columns'))->firstWhere('key', $bonus);
        $this->assertSame(['total_net_payable'], array_column($present['dependents'], 'key'), 'the manager can warn before the attempt');
        $this->assertSame('125916.30', $this->sheetRow('0001')['cells']['total_net_payable']['v']);
        $this->postJson(self::CFG.'/columns/total_net_payable/restore-formula', ['config_revision' => $this->configRevision()])->assertOk();
        $this->deleteJson(self::CFG.'/columns/'.$bonus, ['config_revision' => $this->configRevision(), 'confirm_values' => true])->assertOk();
    }

    public function test_a_stale_configuration_is_an_explicit_conflict_for_editing_the_final_formula(): void
    {
        $this->referenceWithCustomColumns();
        $stale = $this->configRevision();
        $this->patchJson(self::CFG.'/settings', ['config_revision' => $stale, 'settings' => ['income_tax_rate' => '0.10']])->assertOk();
        $this->net(['formula' => self::EDITED, 'config_revision' => $stale])->assertStatus(409)->assertJsonPath('error_code', 'payroll_config_conflict');
        $this->assertSame(PayrollTemplate::NET_PAYABLE_FORMULA, DB::table('payroll_columns')->where('key', 'total_net_payable')->value('formula'));
        // A value save made under the old configuration is a conflict as well, never half-applied.
        $row = $this->sheetRow('0001');
        $this->values([$this->change($row, ['fixed_salary' => '1'])], $stale)->assertStatus(409)->assertJsonPath('error_code', 'payroll_config_conflict');
    }

    public function test_only_users_with_config_manage_can_edit_or_restore_the_final_formula(): void
    {
        $this->referenceWithCustomColumns();
        $permission = DB::table('permissions')->where('permission_code', 'owner_payroll.config.manage')->value('permission_id');
        DB::table('role_permissions')->where('role_id', 2)->where('permission_id', $permission)->delete();
        $this->net(['formula' => self::EDITED])->assertForbidden();
        $this->postJson(self::CFG.'/columns/total_net_payable/restore-formula', ['config_revision' => $this->configRevision()])->assertForbidden();
        $this->postJson(self::CFG.'/preview', ['column' => ['key' => 'total_net_payable', 'formula' => self::EDITED]])->assertForbidden();
        $this->assertSame(PayrollTemplate::NET_PAYABLE_FORMULA, DB::table('payroll_columns')->where('key', 'total_net_payable')->value('formula'));
        foreach ([self::PRESIDENT, self::HR, self::TECH, self::VP_SCIENTIFIC, self::VP_ADMIN, self::OWNER_DISABLED, self::PLAIN, self::ROGUE] as $user) {
            $this->actingAsUser($user);
            $this->net(['formula' => self::EDITED])->assertForbidden();
            $this->postJson(self::CFG.'/columns/total_net_payable/restore-formula', ['config_revision' => 1])->assertForbidden();
        }
        $this->actingAsUser(self::ADMIN);
        $this->net(['formula' => self::EDITED])->assertOk();
    }

    public function test_excel_and_pdf_follow_the_edited_final_formula_and_recalculate_to_the_server_value(): void
    {
        if (! $this->libreOfficeAvailable()) {
            $this->markTestSkipped('LibreOffice (soffice) is required to recalculate the exported workbook.');
        }
        [$bonus] = $this->referenceWithCustomColumns();
        // Hide the custom amounts from the export: the edited net formula still needs them, so they travel as hidden helper columns.
        $this->patchJson(self::CFG.'/columns/'.$bonus, ['visible_export' => false, 'config_revision' => $this->configRevision()])->assertOk();
        $this->net(['formula' => self::EDITED])->assertOk();
        $this->employee(['employee_number' => '0002']);

        $response = $this->getJson(self::API.'/payroll/export/xlsx')->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        $csv = $this->recalculate($file);
        $header = $csv[6];
        $net = collect($header)->search(fn ($h) => str_starts_with($h, 'إجمالي الصافي المستحق'));
        $this->assertSame('125916.3', $csv[7][$net], 'the recalculated workbook equals the application');
        $this->assertSame('', $csv[8][$net], 'the employee without a fixed salary stays unavailable');
        $totalRow = collect($csv)->first(fn ($line) => str_starts_with($line[0] ?? '', 'الإجمالي'));
        $this->assertSame('125916.3', $totalRow[$net]);
        $this->assertTrue(collect($header)->contains(fn ($h) => str_starts_with($h, 'مكافأة إضافية (عمود مساعد)')), 'the referenced custom column is present as a helper');

        $pdf = $this->get(self::API.'/payroll/export/pdf')->assertOk()->getContent();
        if (trim((string) shell_exec('command -v pdftotext')) !== '') {
            $path = tempnam(sys_get_temp_dir(), 'payroll_pdf_').'.pdf';
            file_put_contents($path, $pdf);
            $this->assertStringContainsString('125,916.30', (string) shell_exec('pdftotext '.escapeshellarg($path).' - 2>/dev/null'));
        }
    }
}
