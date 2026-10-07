<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Payroll phase 1.1: Syrian-pound amounts, configurable columns, global calculation settings and the workbook template.
 *
 * ADDITIVE and data-preserving. The tables of 2026_10_08_000000 (which may already be applied) are not altered or dropped:
 * payroll_entries keeps its row revision (it is the per-employee optimistic-concurrency anchor for ALL input values) and its
 * three legacy amount columns stay in place, untouched, as the frozen source of the copy below.
 *
 * Storage scale. Both generations store exact integers; only the scale changed:
 *   legacy  payroll_entries.*_cents          = value x 10^2   (hundredths; the name said "cents", the currency label said USD)
 *   new     payroll_entry_values.value_scaled = value x 10^6   (millionths; room for percentages and rates)
 * The amounts themselves were never converted: no exchange rate is applied. A stored 123450 cents (1,234.50) becomes
 * 1234500000 scaled units, which is the same 1,234.50, now read as Syrian pounds.
 *
 * Mapping of the legacy inputs onto the template columns:
 *   fixed_salary_cents  -> fixed_salary    (الأجر المقطوع)  same meaning
 *   compensation_cents  -> compensation    (التعويض)        same meaning
 *   deduction_cents     -> other_deductions (حسميات أخرى)   the only deduction input that existed before insurance and income
 *                         tax were modelled; it is carried over as typed and is NEVER split into insurance/tax columns.
 * Salary/compensation adjustments start blank. Payable totals will differ from phase 1 because insurance and tax are now
 * calculated; the inputs are unchanged.
 */
return new class extends Migration
{
    private const SCALE_FACTOR = 10000; // 10^6 / 10^2

    public function up(): void
    {
        Schema::create('payroll_config', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary(); // always 1: one configuration of the current working sheet
            $table->unsignedInteger('revision')->default(1);
            $table->integer('updated_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_settings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('key', 40)->unique();
            $table->string('label', 100)->unique();
            $table->string('value_type', 10); // percent | amount | number
            $table->bigInteger('value_scaled'); // x 10^6; a percentage is stored as a fraction (7% = 70000)
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->integer('updated_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_columns', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('key', 40)->unique(); // immutable stable identity used by formulas and values
            $table->string('label', 100)->unique();
            $table->string('column_group', 20); // employee | salary | compensation | deductions | net
            $table->string('kind', 10); // input | formula
            $table->string('value_type', 10); // text | number | amount | percent
            $table->text('formula')->nullable(); // canonical form with stable keys: {fixed_salary} * {insurance_rate}
            $table->boolean('blank_as_zero')->default(true);
            $table->boolean('allow_negative')->default(false);
            $table->boolean('warn_negative')->default(false);
            $table->string('aggregation', 10)->default('none'); // sum | none
            $table->boolean('visible_grid')->default(true);
            $table->boolean('visible_export')->default(true);
            $table->boolean('compact')->default(false);
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->integer('created_by_user_id')->nullable();
            $table->integer('updated_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_entry_values', function (Blueprint $table): void {
            $table->unsignedBigInteger('payroll_employee_id');
            $table->unsignedBigInteger('payroll_column_id');
            $table->bigInteger('value_scaled')->nullable(); // numeric/amount/percent inputs
            $table->string('value_text', 255)->nullable(); // text inputs
            $table->timestamps();
            $table->primary(['payroll_employee_id', 'payroll_column_id']);
            $table->foreign('payroll_employee_id')->references('id')->on('payroll_employees')->restrictOnDelete();
            $table->foreign('payroll_column_id')->references('id')->on('payroll_columns')->cascadeOnDelete();
        });

        $now = now();
        DB::table('payroll_config')->insert(['id' => 1, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now]);

        // Settings supplied by the reference workbook (not a statement about current legislation).
        foreach ([
            ['insurance_rate', 'نسبة التأمينات', 'percent', 70000, 1],
            ['income_tax_rate', 'نسبة ضريبة الدخل', 'percent', 150000, 2],
            ['tax_exemption', 'الحد الأدنى المعفى من الضريبة', 'amount', 12560000000, 3],
        ] as [$key, $label, $type, $scaled, $order]) {
            DB::table('payroll_settings')->insert(['key' => $key, 'label' => $label, 'value_type' => $type, 'value_scaled' => $scaled, 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now]);
        }

        // The workbook template, expressed with the same column/formula infrastructure as any custom column.
        // [key, label, group, kind, formula, blank_as_zero, allow_negative, warn_negative, compact]
        $template = [
            ['fixed_salary', 'الأجر المقطوع', 'salary', 'input', null, false, false, false, true],
            ['salary_adjustment', 'فروقات الراتب', 'salary', 'input', null, true, true, false, true],
            ['salary_entitlement', 'الراتب المستحق', 'salary', 'formula', '{fixed_salary} + {salary_adjustment}', false, false, false, false],
            ['salary_taxable_base', 'الوعاء الضريبي للراتب', 'salary', 'formula', '{salary_entitlement} - {insurance} - {tax_exemption}', false, false, true, false],
            ['compensation', 'التعويض', 'compensation', 'input', null, true, false, false, true],
            ['compensation_adjustment', 'فروقات التعويض', 'compensation', 'input', null, true, true, false, true],
            ['compensation_entitlement', 'التعويض المستحق', 'compensation', 'formula', '{compensation} + {compensation_adjustment}', false, false, false, false],
            ['combined_taxable_base', 'الوعاء الضريبي المجمّع', 'compensation', 'formula', '{salary_entitlement} + {compensation_entitlement} - {insurance} - {tax_exemption}', false, false, true, false],
            ['insurance', 'التأمينات الاجتماعية', 'deductions', 'formula', '{fixed_salary} * {insurance_rate}', false, false, false, false],
            ['salary_tax', 'ضريبة دخل الراتب', 'deductions', 'formula', '{salary_taxable_base} * {income_tax_rate}', false, false, true, false],
            ['compensation_tax', 'ضريبة دخل التعويض', 'deductions', 'formula', '{combined_taxable_base} * {income_tax_rate} - {salary_tax}', false, false, true, false],
            ['other_deductions', 'حسميات أخرى', 'deductions', 'input', null, true, false, false, true],
            ['total_deductions', 'إجمالي الاقتطاعات', 'deductions', 'formula', '{insurance} + {salary_tax} + {compensation_tax} + {other_deductions}', false, false, false, true],
            ['gross_entitlement', 'إجمالي المستحقات (قبل الاقتطاع)', 'net', 'formula', '{salary_entitlement} + {compensation_entitlement}', false, false, false, false],
            ['net_salary', 'صافي الراتب', 'net', 'formula', '{salary_entitlement} - {insurance} - {salary_tax}', false, false, false, false],
            ['net_compensation', 'صافي التعويض', 'net', 'formula', '{compensation_entitlement} - {compensation_tax}', false, false, false, false],
            ['total_net_payable', 'إجمالي الصافي المستحق', 'net', 'formula', '{net_salary} + {net_compensation} - {other_deductions}', false, false, false, true],
        ];
        foreach ($template as $i => [$key, $label, $group, $kind, $formula, $blankZero, $negative, $warn, $compact]) {
            DB::table('payroll_columns')->insert([
                'key' => $key, 'label' => $label, 'column_group' => $group, 'kind' => $kind, 'value_type' => 'amount', 'formula' => $formula,
                'blank_as_zero' => $blankZero, 'allow_negative' => $negative, 'warn_negative' => $warn, 'aggregation' => 'sum',
                'visible_grid' => true, 'visible_export' => true, 'compact' => $compact, 'is_system' => true, 'sort_order' => ($i + 1) * 10,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Copy the legacy amounts exactly (x 10^4 changes the scale, not the value). Rows with NULL stay blank.
        foreach (['fixed_salary_cents' => 'fixed_salary', 'compensation_cents' => 'compensation', 'deduction_cents' => 'other_deductions'] as $legacy => $key) {
            $columnId = DB::table('payroll_columns')->where('key', $key)->value('id');
            DB::table('payroll_entries')->whereNotNull($legacy)->orderBy('payroll_employee_id')->chunk(500, function ($rows) use ($legacy, $columnId, $now): void {
                DB::table('payroll_entry_values')->insert($rows->map(fn ($row) => [
                    'payroll_employee_id' => $row->payroll_employee_id, 'payroll_column_id' => $columnId,
                    'value_scaled' => (int) $row->{$legacy} * self::SCALE_FACTOR, 'created_at' => $now, 'updated_at' => $now,
                ])->all());
            });
        }
    }

    public function down(): void
    {
        // Rollback must never erase saved payroll configuration or values.
        foreach (['payroll_entry_values'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Payroll column rollback refused: preserve existing payroll values.');
            }
        }
        if (Schema::hasTable('payroll_columns') && DB::table('payroll_columns')->where('is_system', false)->exists()) {
            throw new RuntimeException('Payroll column rollback refused: preserve custom column definitions.');
        }
        Schema::dropIfExists('payroll_entry_values');
        Schema::dropIfExists('payroll_columns');
        Schema::dropIfExists('payroll_settings');
        Schema::dropIfExists('payroll_config');
    }
};
