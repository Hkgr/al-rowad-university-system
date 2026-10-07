<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * University-owner payroll working sheet (phase 1).
 *
 * Three independent tables. Nothing here references teachers, employees, HR records, students or
 * accounts: payroll people are records of this feature only. The *_user_id columns are plain
 * integers kept for audit attribution; they are deliberately not foreign keys, so payroll never
 * constrains (or is constrained by) the account tables.
 *
 * Identity/classification (payroll_employees, payroll_bodies) is kept apart from the current
 * financial values (payroll_entries) so a later monthly-cycle phase can add a period key to the
 * financial table without rebuilding employee records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_bodies', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name', 150)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->integer('created_by_user_id')->nullable()->index();
            $table->integer('updated_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_employees', function (Blueprint $table): void {
            $table->bigIncrements('id');
            // Manually entered text code (leading zeros / alphanumerics preserved). Never generated.
            $table->string('employee_number', 64)->unique();
            $table->string('full_name', 255);
            $table->string('job_title', 255);
            $table->unsignedBigInteger('payroll_body_id');
            $table->string('workplace', 20); // afrin | jarablus | afrin_jarablus | other
            $table->string('workplace_other', 150)->nullable();
            $table->string('academic_level', 255)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->integer('created_by_user_id')->nullable();
            $table->integer('updated_by_user_id')->nullable();
            $table->timestamps();
            $table->index('payroll_body_id');
            $table->foreign('payroll_body_id')->references('id')->on('payroll_bodies')->restrictOnDelete();
        });

        // Current working-sheet values: integer USD cents, NULL = blank (distinct from an entered zero). Signed on
        // purpose (MariaDB errors on UNSIGNED subtraction); non-negativity is enforced by the application.
        Schema::create('payroll_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('payroll_employee_id')->primary();
            $table->bigInteger('fixed_salary_cents')->nullable();
            $table->bigInteger('deduction_cents')->nullable();
            $table->bigInteger('compensation_cents')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->integer('updated_by_user_id')->nullable();
            $table->timestamps();
            $table->foreign('payroll_employee_id')->references('id')->on('payroll_employees')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Rollback must never erase saved payroll data.
        foreach (['payroll_entries', 'payroll_employees', 'payroll_bodies'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Owner payroll rollback refused: preserve existing payroll records.');
            }
        }
        Schema::dropIfExists('payroll_entries');
        Schema::dropIfExists('payroll_employees');
        Schema::dropIfExists('payroll_bodies');
    }
};
