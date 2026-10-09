<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'sqlite'], true) || ! Schema::hasColumns('payroll_employees', ['employee_id', 'revision']) || ! Schema::hasTable('payroll_payments')) {
            throw new RuntimeException('Install the existing HR/payroll/payment schema first; MariaDB/MySQL or SQLite required.');
        }
        if ($driver === 'mysql') {
            foreach (['employees', 'users', 'payroll_employees', 'payroll_entries', 'payroll_payments', 'payroll_config'] as $parent) {
                if (DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $parent)->value('ENGINE') !== 'InnoDB') {
                    throw new RuntimeException('Existing InnoDB parent required: '.$parent);
                }
            }
        }
        // Unknown HR classification is NULL, never an invented contract/body/location.
        Schema::table('payroll_employees', function (Blueprint $t): void {
            $t->string('job_title', 255)->nullable()->change();
            $t->unsignedBigInteger('payroll_body_id')->nullable()->change();
            $t->string('workplace', 20)->nullable()->change();
        });
        // Legacy trial numbers must not prevent a real personnel insert. employee_id remains unique.
        if (Schema::hasIndex('payroll_employees', 'payroll_employees_employee_number_unique')) {
            Schema::table('payroll_employees', fn (Blueprint $t) => $t->dropUnique('payroll_employees_employee_number_unique'));
        }
        if (Schema::hasColumn('payroll_config', 'personnel_reset_completed_at') && ! Schema::hasColumns('payroll_config', ['personnel_reset_manifest', 'personnel_reset_receipt'])) {
            throw new RuntimeException('Partial maintenance receipt schema: stop and review; do not guess historical state.');
        }
        if (! Schema::hasColumn('payroll_config', 'personnel_reset_completed_at')) {
            Schema::table('payroll_config', function (Blueprint $t): void {
                $t->dateTime('personnel_reset_completed_at')->nullable();
                $t->char('personnel_reset_manifest', 64)->nullable();
                $t->json('personnel_reset_receipt')->nullable();
            });
        }
        if (! Schema::hasColumn('payroll_payments', 'period_revision_at_record')) {
            Schema::table('payroll_payments', fn (Blueprint $t) => $t->unsignedBigInteger('period_revision_at_record')->nullable());
        }
        if (! Schema::hasTable('hr_work_time_records')) {
            Schema::create('hr_work_time_records', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->bigIncrements('id');
                $t->integer('employee_id');
                $t->foreign('employee_id')->references('employee_id')->on('employees')->restrictOnDelete();
                $t->string('period', 7);
                $t->decimal('days', 6, 2)->nullable();
                $t->decimal('hours', 8, 2)->nullable();
                $t->text('source_reference');
                $t->unsignedBigInteger('revision')->default(1);
                $t->integer('recorded_by_user_id');
                $t->foreign('recorded_by_user_id')->references('user_id')->on('users')->restrictOnDelete();
                $t->timestamps();
                $t->unique(['employee_id', 'period']);
            });
        }
        if (! Schema::hasTable('payroll_periods')) {
            Schema::create('payroll_periods', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->bigIncrements('id');
                $t->string('period', 7)->unique();
                $t->enum('status', ['draft', 'approved'])->default('draft');
                $t->unsignedBigInteger('revision')->default(1);
                $t->json('config_snapshot');
                $t->integer('created_by_user_id');
                $t->foreign('created_by_user_id')->references('user_id')->on('users')->restrictOnDelete();
                $t->integer('approved_by_user_id')->nullable();
                $t->foreign('approved_by_user_id')->references('user_id')->on('users')->restrictOnDelete();
                $t->dateTime('approved_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('payroll_period_entries')) {
            Schema::create('payroll_period_entries', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->bigIncrements('id');
                $t->unsignedBigInteger('period_id');
                $t->foreign('period_id')->references('id')->on('payroll_periods')->restrictOnDelete();
                $t->unsignedBigInteger('payroll_employee_id');
                $t->foreign('payroll_employee_id')->references('id')->on('payroll_employees')->restrictOnDelete();
                $t->json('identity_snapshot');
                $t->json('work_time_snapshot')->nullable();
                $t->json('inputs');
                $t->json('cells');
                $t->unsignedBigInteger('revision')->default(1);
                $t->timestamps();
                $t->unique(['period_id', 'payroll_employee_id']);
            });
        }
        if (! Schema::hasTable('payroll_period_history')) {
            Schema::create('payroll_period_history', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->bigIncrements('id');
                $t->unsignedBigInteger('period_id');
                $t->foreign('period_id')->references('id')->on('payroll_periods')->restrictOnDelete();
                $t->unsignedBigInteger('revision');
                $t->json('snapshot');
                $t->integer('actor_user_id');
                $t->foreign('actor_user_id')->references('user_id')->on('users')->restrictOnDelete();
                $t->text('reason');
                $t->dateTime('created_at');
                $t->unique(['period_id', 'revision']);
            });
        }
        if (! Schema::hasTable('payroll_period_events')) {
            Schema::create('payroll_period_events', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->bigIncrements('id');
                $t->uuid('request_id')->unique();
                $t->char('payload_hash', 64);
                $t->unsignedBigInteger('period_id');
                $t->foreign('period_id')->references('id')->on('payroll_periods')->restrictOnDelete();
                $t->string('action', 30);
                $t->integer('actor_user_id');
                $t->foreign('actor_user_id')->references('user_id')->on('users')->restrictOnDelete();
                $t->json('details');
                $t->json('result');
                $t->dateTime('created_at');
                $t->index(['period_id', 'id']);
            });
        }
        if ($driver === 'mysql') {
            if (! DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', 'employee_payroll_profile')->exists()) {
                DB::unprepared("CREATE TRIGGER employee_payroll_profile AFTER INSERT ON employees FOR EACH ROW BEGIN INSERT INTO payroll_employees (employee_id,employee_number,full_name,revision,created_at,updated_at) VALUES (NEW.employee_id,NEW.employee_number,TRIM(CONCAT(NEW.first_name,' ',NEW.last_name)),1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP); INSERT INTO payroll_entries (payroll_employee_id,revision,created_at,updated_at) SELECT id,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP FROM payroll_employees WHERE employee_id=NEW.employee_id; END");
            }
            if (! DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', 'payroll_personnel_identity')->exists()) {
                DB::unprepared("CREATE TRIGGER payroll_personnel_identity BEFORE INSERT ON payroll_employees FOR EACH ROW BEGIN IF NEW.employee_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Payroll requires a real employee_id'; END IF; END");
            }
        } else {
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS employee_payroll_profile AFTER INSERT ON employees BEGIN INSERT INTO payroll_employees (employee_id,employee_number,full_name,revision,created_at,updated_at) VALUES (NEW.employee_id,NEW.employee_number,TRIM(NEW.first_name || ' ' || NEW.last_name),1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP); INSERT INTO payroll_entries (payroll_employee_id,revision,created_at,updated_at) SELECT id,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP FROM payroll_employees WHERE employee_id=NEW.employee_id; END");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS payroll_personnel_identity BEFORE INSERT ON payroll_employees WHEN NEW.employee_id IS NULL BEGIN SELECT RAISE(ABORT,'Payroll requires a real employee_id'); END");
        }
        // No synchronization, trial deletion, inferred financial amounts or history rewrite during migration.
    }

    public function down(): void
    {
        throw new RuntimeException('Rollback refused: preserve personnel-linked financial and monthly accounting history.');
    }
};
