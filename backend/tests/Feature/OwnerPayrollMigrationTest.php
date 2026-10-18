<?php

namespace Tests\Feature;

use App\Services\Payroll\PayrollCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Data preservation: values entered under the phase-1 schema (integer cents, labelled USD) keep exactly the same numeric value
 * after the additive SYP template migration, nothing is redistributed, and the migration refuses to roll back over real data.
 */
final class OwnerPayrollMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::disableForeignKeyConstraints();
            Schema::dropAllTables();
        }
        parent::tearDown();
    }

    private function phaseOne(): void
    {
        (require base_path('database/migrations/2026_10_08_000000_create_owner_payroll_tables.php'))->up();
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_10_09_000000_add_payroll_columns_settings_and_syp_template.php');
    }

    public function test_previously_entered_values_keep_their_numeric_value_and_blanks_stay_blank(): void
    {
        $this->phaseOne();
        DB::table('payroll_bodies')->insert(['id' => 1, 'name' => 'هيئة', 'is_active' => 1, 'revision' => 1]);
        foreach ([[1, '00012'], [2, 'A-7'], [3, '0003']] as [$id, $number]) {
            DB::table('payroll_employees')->insert(['id' => $id, 'employee_number' => $number, 'full_name' => "موظف {$id}", 'job_title' => 'x', 'payroll_body_id' => 1, 'workplace' => 'afrin', 'revision' => 1]);
        }
        DB::table('payroll_entries')->insert([
            ['payroll_employee_id' => 1, 'fixed_salary_cents' => 9660000, 'deduction_cents' => 12345, 'compensation_cents' => 5520050, 'revision' => 4],
            ['payroll_employee_id' => 2, 'fixed_salary_cents' => 0, 'deduction_cents' => null, 'compensation_cents' => null, 'revision' => 2],
            ['payroll_employee_id' => 3, 'fixed_salary_cents' => null, 'deduction_cents' => 1, 'compensation_cents' => null, 'revision' => 1],
        ]);

        $this->migration()->up();

        $value = fn (int $employee, string $key) => DB::table('payroll_entry_values')->where('payroll_employee_id', $employee)
            ->where('payroll_column_id', DB::table('payroll_columns')->where('key', $key)->value('id'))->first();
        // 96,600.00 / 123.45 / 55,200.50 in cents -> the same numbers in the new scale.
        $this->assertEquals('96600.00', PayrollCalculator::format(PayrollCalculator::fromStored((int) $value(1, 'fixed_salary')->value_scaled), 'amount'));
        $this->assertEquals('123.45', PayrollCalculator::format(PayrollCalculator::fromStored((int) $value(1, 'other_deductions')->value_scaled), 'amount'));
        $this->assertEquals('55200.50', PayrollCalculator::format(PayrollCalculator::fromStored((int) $value(1, 'compensation')->value_scaled), 'amount'));
        // An entered zero stays a zero; a NULL stays absent (blank), never zero-filled.
        $this->assertSame(0, (int) $value(2, 'fixed_salary')->value_scaled);
        $this->assertNull($value(2, 'other_deductions'));
        $this->assertNull($value(3, 'fixed_salary'));
        $this->assertEquals('0.01', PayrollCalculator::format(PayrollCalculator::fromStored((int) $value(3, 'other_deductions')->value_scaled), 'amount'));
        // The legacy aggregate deduction is NOT redistributed into insurance or tax columns: only the "other deductions" input holds it.
        $this->assertSame(0, DB::table('payroll_entry_values')->whereIn('payroll_column_id', DB::table('payroll_columns')->whereIn('key', ['insurance', 'salary_tax', 'compensation_tax'])->pluck('id'))->count());
        // Additive: employees, numbers (leading zeros), revisions and the legacy columns are untouched.
        $this->assertSame(['00012', 'A-7', '0003'], DB::table('payroll_employees')->orderBy('id')->pluck('employee_number')->all());
        $this->assertSame(4, (int) DB::table('payroll_entries')->where('payroll_employee_id', 1)->value('revision'));
        $this->assertSame(9660000, (int) DB::table('payroll_entries')->where('payroll_employee_id', 1)->value('fixed_salary_cents'));
        $this->assertTrue(Schema::hasColumns('payroll_entries', ['fixed_salary_cents', 'deduction_cents', 'compensation_cents']));
        // Seeded template: 17 columns, 3 settings, config revision 1.
        $this->assertSame(17, DB::table('payroll_columns')->count());
        $this->assertSame(['0.07', '0.15', '12560'], collect(DB::table('payroll_settings')->orderBy('sort_order')->get())->map(fn ($s) => PayrollCalculator::plain(PayrollCalculator::fromStored((int) $s->value_scaled)))->all());
        $this->assertSame(1, (int) DB::table('payroll_config')->value('revision'));
    }

    public function test_rollback_refuses_to_erase_values_or_custom_columns(): void
    {
        $this->phaseOne();
        $migration = $this->migration();
        $migration->up();
        $migration->down(); // empty: allowed
        $this->assertFalse(Schema::hasTable('payroll_columns'));

        $migration->up();
        DB::table('payroll_bodies')->insert(['id' => 1, 'name' => 'هيئة', 'is_active' => 1, 'revision' => 1]);
        DB::table('payroll_employees')->insert(['id' => 1, 'employee_number' => '1', 'full_name' => 'x', 'job_title' => 'x', 'payroll_body_id' => 1, 'workplace' => 'afrin', 'revision' => 1]);
        DB::table('payroll_entry_values')->insert(['payroll_employee_id' => 1, 'payroll_column_id' => DB::table('payroll_columns')->where('key', 'fixed_salary')->value('id'), 'value_scaled' => 1000000]);
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_the_migration_has_no_destructive_statement_on_existing_payroll_data(): void
    {
        $source = file_get_contents(base_path('database/migrations/2026_10_09_000000_add_payroll_columns_settings_and_syp_template.php'));
        $up = substr($source, strpos($source, 'public function up'), strpos($source, 'public function down') - strpos($source, 'public function up'));
        foreach (['dropColumn', 'dropIfExists', 'drop(', '->delete()', 'truncate', 'DROP '] as $needle) {
            $this->assertStringNotContainsString($needle, $up, "up() must stay additive: {$needle}");
        }
    }
}
