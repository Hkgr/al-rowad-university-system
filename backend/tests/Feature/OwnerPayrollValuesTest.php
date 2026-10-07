<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * Saving cell values: strict parsing, exact storage, all-or-nothing batches and explicit conflicts.
 */
final class OwnerPayrollValuesTest extends OwnerPayrollTestCase
{
    private function stored(int $employee, string $key): ?int
    {
        $v = DB::table('payroll_entry_values')->where('payroll_employee_id', $employee)->where('payroll_column_id', DB::table('payroll_columns')->where('key', $key)->value('id'))->value('value_scaled');

        return $v === null ? null : (int) $v;
    }

    public function test_exact_decimal_storage_blank_versus_zero_and_clearing(): void
    {
        $this->actingAsUser(self::OWNER);
        $row = $this->employee();
        $r = $this->values([$this->change($row, ['fixed_salary' => '0.10', 'other_deductions' => '0'])])->assertOk()->json('data.0');
        $this->assertSame(100000, $this->stored($row['id'], 'fixed_salary'), 'value x 10^6, exact');
        $this->assertSame(0, $this->stored($row['id'], 'other_deductions'), 'an entered zero is stored');
        $this->assertNull($this->stored($row['id'], 'compensation'), 'untouched cells stay blank');
        $this->assertSame('0.00', $r['cells']['other_deductions']['v']);
        $this->assertNull($r['cells']['compensation']['v']);

        // 0.10 + 0.20 has no binary-float drift anywhere.
        $r = $this->values([$this->change($r, ['fixed_salary' => '0.10', 'salary_adjustment' => '0.20'])])->assertOk()->json('data.0');
        $this->assertSame('0.30', $r['cells']['salary_entitlement']['v']);

        // Clearing returns to blank (the row disappears), which differs from zero.
        $r = $this->values([$this->change($r, ['other_deductions' => null])])->assertOk()->json('data.0');
        $this->assertNull($r['cells']['other_deductions']['v']);
        $this->assertNull($this->stored($row['id'], 'other_deductions'));
        $this->assertSame(0, DB::table('payroll_entry_values')->where('payroll_employee_id', $row['id'])->whereNull('value_scaled')->count());
        $this->assertSame(self::OWNER, (int) DB::table('payroll_entries')->where('payroll_employee_id', $row['id'])->value('updated_by_user_id'));
    }

    public function test_a_save_that_changes_nothing_does_not_bump_the_revision(): void
    {
        $this->actingAsUser(self::OWNER);
        $row = $this->employee();
        $r = $this->values([$this->change($row, ['fixed_salary' => '10'])])->assertOk()->json('data.0');
        $this->assertSame(2, $r['entry_revision']);
        $again = $this->values([$this->change($r, ['fixed_salary' => '10.00'])])->assertOk()->json('data.0');
        $this->assertSame(2, $again['entry_revision']);
    }

    public function test_invalid_values_are_rejected_without_writing_anything(): void
    {
        $this->actingAsUser(self::OWNER);
        $a = $this->employee(['employee_number' => 'V-1']);
        $b = $this->employee(['employee_number' => 'V-2']);
        foreach (['-1', '-0.01', '1e3', '12.345', 'abc', '1,000', '٣٠', '1000000000', '+5', '1.', '.5', true, 1.5, ['x']] as $bad) {
            $this->values([$this->change($a, ['fixed_salary' => '5']), $this->change($b, ['other_deductions' => $bad])])
                ->assertUnprocessable()->assertJsonStructure(['errors' => ['changes.1.values.other_deductions']]);
        }
        $this->assertSame(0, DB::table('payroll_entry_values')->count(), 'the valid half of an invalid batch is not committed');
        $this->values([])->assertUnprocessable();
        $this->values([['employee_id' => $a['id'], 'expected_revision' => 1, 'values' => []]])->assertUnprocessable();
        $this->values([$this->change($a, ['fixed_salary' => '1']), $this->change($a, ['other_deductions' => '1'])])->assertUnprocessable();
        // Signed columns take negatives, plain ones do not; a calculated column is never accepted from the client.
        $this->values([$this->change($a, ['salary_adjustment' => '-12.5'])])->assertOk();
        $this->values([$this->change($this->sheetRow('V-1'), ['total_net_payable' => '9999'])])->assertUnprocessable();
        $this->patchJson(self::API.'/payroll/values', ['changes' => [$this->change($a, ['fixed_salary' => '1'])]])->assertUnprocessable()->assertJsonStructure(['errors' => ['config_revision']]);
    }

    public function test_stale_row_revision_is_an_explicit_conflict_and_the_batch_is_all_or_nothing(): void
    {
        $this->actingAsUser(self::OWNER);
        $a = $this->employee(['employee_number' => 'C-1']);
        $b = $this->employee(['employee_number' => 'C-2']);
        $this->values([$this->change($a, ['fixed_salary' => '100'])])->assertOk();

        $response = $this->values([$this->change($a, ['fixed_salary' => '999']), $this->change($b, ['fixed_salary' => '50'])])->assertStatus(409);
        $response->assertJsonPath('error_code', 'payroll_conflict')->assertJsonPath('data.conflicts.0.employee_id', $a['id'])
            ->assertJsonPath('data.conflicts.0.current.cells.fixed_salary.v', '100.00');
        $this->assertNull($this->stored($b['id'], 'fixed_salary'), 'the non-stale half is not committed');
        $this->assertSame(100000000, $this->stored($a['id'], 'fixed_salary'));
        $this->values([['employee_id' => 9999, 'expected_revision' => 1, 'values' => ['fixed_salary' => '1']]])->assertNotFound();
    }

    public function test_batches_use_stable_ids_after_sorting_and_filtering_and_commit_atomically(): void
    {
        $this->actingAsUser(self::OWNER);
        $one = $this->body('الهيئة ألف');
        $two = $this->body('الهيئة باء');
        $x = $this->employee(['employee_number' => '010', 'full_name' => 'جيم'], $one);
        $y = $this->employee(['employee_number' => '002', 'full_name' => 'ألف', 'workplace' => 'jarablus'], $two);
        $z = $this->employee(['employee_number' => '003', 'full_name' => 'باء'], $two);

        $rows = $this->getJson(self::API.'/payroll/sheet?sort=full_name&direction=desc&body_id='.$two['id'])->assertOk()->json('data');
        $this->assertSame([$z['id'], $y['id']], array_column($rows, 'id'));
        $this->values([$this->change($rows[1], ['fixed_salary' => '700', 'other_deductions' => '20'])])->assertOk();
        $this->assertSame(700000000, $this->stored($y['id'], 'fixed_salary'));
        $this->assertNull($this->stored($x['id'], 'fixed_salary'), 'the first-created employee is untouched');

        $this->values([
            $this->change($x, ['fixed_salary' => '1']), $this->change($this->sheetRow('002'), ['fixed_salary' => '2']), $this->change($z, ['compensation' => '3']),
        ])->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame([1000000, 2000000, null], [$this->stored($x['id'], 'fixed_salary'), $this->stored($y['id'], 'fixed_salary'), $this->stored($z['id'], 'fixed_salary')]);
    }

    public function test_a_database_failure_midway_rolls_the_whole_batch_back(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Uses a SQLite trigger to inject the failure.');
        }
        $this->actingAsUser(self::OWNER);
        $a = $this->employee(['employee_number' => 'T-1']);
        $b = $this->employee(['employee_number' => 'T-2']);
        // Break the second employee's value insert so the failure happens after the first employee was already written.
        DB::statement('CREATE TRIGGER fail_second BEFORE INSERT ON payroll_entry_values WHEN NEW.payroll_employee_id = '.$b['id'].' BEGIN SELECT RAISE(ABORT, "boom"); END');
        $this->withoutExceptionHandling();
        try {
            $this->values([$this->change($a, ['fixed_salary' => '10']), $this->change($b, ['fixed_salary' => '20'])]);
            $this->fail('expected the injected failure');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
        }
        DB::statement('DROP TRIGGER fail_second');
        $this->assertSame(0, DB::table('payroll_entry_values')->count(), 'nothing committed');
        $this->assertSame([1, 1], DB::table('payroll_entries')->orderBy('payroll_employee_id')->pluck('revision')->map(fn ($r) => (int) $r)->all(), 'revisions unchanged');
    }
}
