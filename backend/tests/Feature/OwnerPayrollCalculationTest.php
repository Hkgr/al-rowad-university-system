<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * The workbook calculation (all amounts in Syrian pounds) through the one calculator, over real HTTP.
 */
final class OwnerPayrollCalculationTest extends OwnerPayrollTestCase
{
    private function cells(array $row, array $keys): array
    {
        return collect($keys)->mapWithKeys(fn ($k) => [$k => $row['cells'][$k]['v']])->all();
    }

    public function test_reference_case_matches_the_workbook_exactly(): void
    {
        $this->actingAsUser(self::OWNER);
        $row = $this->employee(['employee_number' => '0001']);
        $r = $this->values([$this->change($row, [
            'fixed_salary' => '96600', 'salary_adjustment' => '0', 'compensation' => '55200', 'compensation_adjustment' => '0', 'other_deductions' => '0',
        ])])->assertOk()->json('data.0');

        $this->assertSame([
            'salary_entitlement' => '96600.00', 'insurance' => '6762.00', 'salary_taxable_base' => '77278.00', 'salary_tax' => '11591.70',
            'compensation_entitlement' => '55200.00', 'combined_taxable_base' => '132478.00', 'compensation_tax' => '8280.00',
            'total_deductions' => '26633.70', 'gross_entitlement' => '151800.00', 'net_salary' => '78246.30', 'net_compensation' => '46920.00',
            'total_net_payable' => '125166.30',
        ], $this->cells($r, ['salary_entitlement', 'insurance', 'salary_taxable_base', 'salary_tax', 'compensation_entitlement', 'combined_taxable_base', 'compensation_tax', 'total_deductions', 'gross_entitlement', 'net_salary', 'net_compensation', 'total_net_payable']));
    }

    public function test_blank_versus_zero_signed_adjustments_and_unavailable_results(): void
    {
        $this->actingAsUser(self::OWNER);
        $row = $this->employee();
        $this->assertSame('missing', $row['cells']['total_net_payable']['st']);
        $this->assertNull($row['cells']['total_net_payable']['v']);
        $this->assertStringContainsString('الأجر المقطوع', $row['cells']['total_net_payable']['m']);

        // Blank fixed salary: dependents stay unavailable even with other inputs; optional blanks never get overwritten.
        $r = $this->values([$this->change($row, ['compensation' => '500', 'other_deductions' => '10'])])->assertOk()->json('data.0');
        $this->assertSame('missing', $r['cells']['salary_entitlement']['st']);
        $this->assertSame('missing', $r['cells']['total_net_payable']['st']);
        $this->assertNull($r['cells']['fixed_salary']['v']);

        // Fixed salary only: optional blanks count as zero in calculations but stay blank cells.
        $r = $this->values([$this->change($r, ['fixed_salary' => '20000'])])->assertOk()->json('data.0');
        $this->assertNull($r['cells']['salary_adjustment']['v']);
        $this->assertSame('20000.00', $r['cells']['salary_entitlement']['v']);
        $this->assertNull(DB::table('payroll_entry_values')->where('payroll_employee_id', $r['id'])->whereIn('payroll_column_id', DB::table('payroll_columns')->where('key', 'salary_adjustment')->pluck('id'))->first());

        // Signed adjustments: insurance uses the fixed salary only, never the adjusted salary.
        $r = $this->values([$this->change($r, ['fixed_salary' => '100000', 'salary_adjustment' => '-5000.50', 'compensation_adjustment' => '-100', 'compensation' => '1000'])])->assertOk()->json('data.0');
        $this->assertSame('94999.50', $r['cells']['salary_entitlement']['v']);
        $this->assertSame('7000.00', $r['cells']['insurance']['v']);
        $this->assertSame('900.00', $r['cells']['compensation_entitlement']['v']);

        // Negative values are rejected in non-signed columns and accepted in signed ones.
        $this->values([$this->change($r, ['fixed_salary' => '-1'])])->assertUnprocessable()->assertJsonStructure(['errors' => ['changes.0.values.fixed_salary']]);
        $this->values([$this->change($r, ['other_deductions' => '-0.01'])])->assertUnprocessable();
        $this->values([$this->change($r, ['salary_adjustment' => '-1'])])->assertOk();
    }

    public function test_negative_taxable_base_is_not_floored_and_is_flagged(): void
    {
        $this->actingAsUser(self::OWNER);
        $row = $this->employee();
        // 10,000 fixed: insurance 700; base = 10000-700-12560 = -3260 => tax -489.00 (no MAX(0,...)).
        $r = $this->values([$this->change($row, ['fixed_salary' => '10000'])])->assertOk()->json('data.0');
        $this->assertSame('-3260.00', $r['cells']['salary_taxable_base']['v']);
        $this->assertSame('warning', $r['cells']['salary_taxable_base']['st']);
        $this->assertSame('-489.00', $r['cells']['salary_tax']['v']);
        $this->assertSame('warning', $r['cells']['salary_tax']['st']);
        $this->assertSame('10000.00', $r['cells']['gross_entitlement']['v']);
        $this->assertSame('9789.00', $r['cells']['net_salary']['v']);
    }

    public function test_rounding_is_half_up_at_each_displayed_monetary_step(): void
    {
        $this->actingAsUser(self::OWNER);
        $row = $this->employee();
        // 12345.67 x 7% = 864.1969 -> 864.20 (the rounded figure feeds dependents).
        $r = $this->values([$this->change($row, ['fixed_salary' => '12345.67'])])->assertOk()->json('data.0');
        $this->assertSame('864.20', $r['cells']['insurance']['v']);
        // 0.50 x 7% = 0.035 -> 0.04 (half away from zero), and a negative half rounds away from zero as well.
        $r = $this->values([$this->change($r, ['fixed_salary' => '0.50'])])->assertOk()->json('data.0');
        $this->assertSame('0.04', $r['cells']['insurance']['v']);
        $this->assertSame('-12559.54', $r['cells']['salary_taxable_base']['v']);
    }

    public function test_settings_change_recalculates_every_row_atomically_with_revision_protection(): void
    {
        $this->actingAsUser(self::OWNER);
        $row = $this->employee(['employee_number' => '0001']);
        $this->values([$this->change($row, ['fixed_salary' => '96600', 'compensation' => '55200'])])->assertOk();
        $revision = $this->configRevision();

        $preview = $this->postJson(self::API.'/payroll/config/preview', ['settings' => ['insurance_rate' => '0.10'], 'employee_id' => $row['id']])->assertOk()->json('data');
        $this->assertSame('1', (string) $preview['employees_changed']);
        $this->assertNotSame($preview['net_payable']['before']['sum'], $preview['net_payable']['after']['sum']);
        $this->assertSame($revision, $this->configRevision(), 'a preview never saves');

        $this->patchJson(self::API.'/payroll/config/settings', ['config_revision' => $revision, 'settings' => ['insurance_rate' => '0.10', 'income_tax_rate' => 'abc']])->assertUnprocessable();
        $this->assertSame($revision, $this->configRevision());
        $this->patchJson(self::API.'/payroll/config/settings', ['config_revision' => $revision, 'settings' => ['insurance_rate' => '0.10']])->assertOk();
        $this->assertSame($revision + 1, $this->configRevision());
        $this->assertSame(self::OWNER, (int) DB::table('payroll_config')->value('updated_by_user_id'));
        $this->assertSame('9660.00', $this->sheetRow('0001')['cells']['insurance']['v']);

        // A stale revision is an explicit conflict and writes nothing.
        $this->patchJson(self::API.'/payroll/config/settings', ['config_revision' => $revision, 'settings' => ['insurance_rate' => '0.01']])
            ->assertStatus(409)->assertJsonPath('error_code', 'payroll_config_conflict');
        $this->assertSame('9660.00', $this->sheetRow('0001')['cells']['insurance']['v']);
        // Saving values against a stale configuration is a conflict too; pending values are not lost server-side, nothing is written.
        $fresh = $this->sheetRow('0001');
        $this->values([$this->change($fresh, ['fixed_salary' => '1'])], $revision)->assertStatus(409)->assertJsonPath('error_code', 'payroll_config_conflict');
        $this->assertSame('96600.00', $this->sheetRow('0001')['cells']['fixed_salary']['v']);
    }

    /**
     * The template configuration (with parsed formulas) and the server's results for a spread of inputs. The browser engine is tested
     * against this same file (frontend/tests/ownerPayrollLogic.test.mjs), so the workbook calculation cannot differ between the two.
     * Regenerate with UPDATE_PAYROLL_VECTORS=1 after an intentional change.
     */
    public function test_template_fixture_for_the_browser_engine_matches_the_server(): void
    {
        $this->actingAsUser(self::OWNER);
        $config = $this->getJson(self::API.'/payroll/config')->assertOk()->json('data');
        $cases = [
            'reference' => ['fixed_salary' => '96600', 'salary_adjustment' => '0', 'compensation' => '55200', 'compensation_adjustment' => '0', 'other_deductions' => '0'],
            'blank optional inputs' => ['fixed_salary' => '20000'],
            'signed adjustments' => ['fixed_salary' => '100000', 'salary_adjustment' => '-5000.50', 'compensation' => '1000', 'compensation_adjustment' => '-100'],
            'negative taxable base' => ['fixed_salary' => '10000'],
            'rounding tie' => ['fixed_salary' => '20000', 'salary_adjustment' => '-1500.50', 'other_deductions' => '250'],
            'missing fixed salary' => ['compensation' => '500', 'other_deductions' => '10'],
            'large values' => ['fixed_salary' => '999999999.99', 'compensation' => '999999999.99', 'salary_adjustment' => '123456.78'],
            'all zero' => ['fixed_salary' => '0', 'compensation' => '0', 'other_deductions' => '0'],
        ];
        $fixture = ['config' => $config, 'cases' => []];
        $i = 0;
        foreach ($cases as $name => $values) {
            $row = $this->employee(['employee_number' => 'T'.(++$i)]);
            $saved = $this->values([$this->change($row, $values)])->assertOk()->json('data.0');
            $fixture['cases'][] = ['name' => $name, 'inputs' => (object) $values, 'cells' => $saved['cells']];
        }
        $json = json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $file = __DIR__.'/../Fixtures/payroll_template_reference.json';
        if (getenv('UPDATE_PAYROLL_VECTORS') === '1') {
            file_put_contents($file, $json);
        }
        $this->assertSame(file_get_contents($file), $json, 'fixture out of date: run with UPDATE_PAYROLL_VECTORS=1');
        $this->assertSame('125166.30', $fixture['cases'][0]['cells']['total_net_payable']['v']);
    }
}
