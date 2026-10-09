<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (['employees', 'users', 'colleges', 'organizational_units', 'positions', 'payroll_employees'] as $table) {
                $engine = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->value('ENGINE');
                if ($engine !== 'InnoDB') {
                    throw new RuntimeException('HR requires existing InnoDB parent '.$table.'; no automatic engine conversion is permitted.');
                }
            }
        }
        // Legacy employee/user/unit keys are SIGNED INT; payroll keys are unsigned BIGINT.
        // No inferred classification, contract, account, affiliation or payroll backfill.
        if (! Schema::hasColumn('employees', 'hr_revision')) {
            Schema::table('employees', function (Blueprint $t): void {
                $t->unsignedBigInteger('hr_revision')->default(1);
                $t->string('hr_body', 20)->nullable();
            });
        }
        $legacy = function (Blueprint $t, string $column, string $table, string $key, bool $nullable = true): void {
            $t->integer($column)->nullable($nullable);
            $t->foreign($column)->references($key)->on($table)->restrictOnDelete();
        };
        if (! Schema::hasTable('hr_staffing_needs')) {
            Schema::create('hr_staffing_needs', function (Blueprint $t) use ($legacy): void {
                $t->engine = 'InnoDB';
                $t->bigIncrements('id');
                $t->string('title', 200);
                $t->string('body', 20);
                $legacy($t, 'college_id', 'colleges', 'college_id');
                $legacy($t, 'organizational_unit_id', 'organizational_units', 'organizational_unit_id', false);
                $t->text('notes')->nullable();
                $t->string('status', 20)->default('open');
                $t->unsignedBigInteger('revision')->default(1);
                $legacy($t, 'created_by_user_id', 'users', 'user_id', false);
                $t->timestamps();
                $t->index(['organizational_unit_id', 'status']);
            });
        }
        if (! Schema::hasTable('hr_staffing_need_items')) {
            Schema::create('hr_staffing_need_items', function (Blueprint $t) use ($legacy): void {
                $t->engine = 'InnoDB';
                $t->bigIncrements('id');
                $t->unsignedBigInteger('need_id');
                $t->foreign('need_id')->references('id')->on('hr_staffing_needs')->restrictOnDelete();
                $legacy($t, 'position_id', 'positions', 'position_id');
                $t->string('job_title', 200);
                $t->unsignedInteger('quantity');
                foreach (['education', 'specialization', 'skills', 'experience', 'notes'] as $column) {
                    $t->text($column)->nullable();
                }
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('hr_candidates')) {
            Schema::create('hr_candidates', function (Blueprint $t) use ($legacy): void {
                $t->engine = 'InnoDB';
                $t->bigIncrements('id');
                $t->unsignedBigInteger('need_item_id');
                $t->foreign('need_item_id')->references('id')->on('hr_staffing_need_items')->restrictOnDelete();
                $legacy($t, 'employee_id', 'employees', 'employee_id');
                $t->string('first_name', 100);
                $t->string('last_name', 100);
                $t->string('father_name', 100)->nullable();
                $t->string('phone_number', 30)->nullable();
                $t->string('email', 150)->nullable();
                foreach (['education', 'specialization', 'skills', 'experience', 'notes', 'decision_reason'] as $column) {
                    $t->text($column)->nullable();
                }
                $t->string('status', 20)->default('candidate');
                $t->unsignedBigInteger('revision')->default(1);
                $legacy($t, 'created_by_user_id', 'users', 'user_id', false);
                $t->timestamps();
                $t->index(['need_item_id', 'status']);
            });
        }
        if (! Schema::hasTable('hr_interviews')) {
            Schema::create('hr_interviews', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->bigIncrements('id');
                $t->unsignedBigInteger('candidate_id');
                $t->foreign('candidate_id')->references('id')->on('hr_candidates')->restrictOnDelete();
                $t->dateTime('scheduled_at');
                $t->string('status', 20);
                $t->text('notes')->nullable();
                $t->text('evaluation')->nullable();
                $t->text('result')->nullable();
                $t->unsignedBigInteger('revision')->default(1);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('hr_interview_participants')) {
            Schema::create('hr_interview_participants', function (Blueprint $t) use ($legacy): void {
                $t->engine = 'InnoDB';
                $t->bigIncrements('id');
                $t->unsignedBigInteger('interview_id');
                $t->foreign('interview_id')->references('id')->on('hr_interviews')->restrictOnDelete();
                $legacy($t, 'employee_id', 'employees', 'employee_id', false);
                $t->unique(['interview_id', 'employee_id']);
            });
        }
        if (! Schema::hasTable('hr_relationship_requests')) {
            Schema::create('hr_relationship_requests', function (Blueprint $t) use ($legacy): void {
                $t->engine = 'InnoDB';
                $t->bigIncrements('id');
                $t->string('target_key', 80);
                $t->unsignedTinyInteger('current_slot')->nullable()->default(1);
                $t->unique(['target_key', 'current_slot'], 'hr_request_current_target');
                $legacy($t, 'employee_id', 'employees', 'employee_id');
                $t->unsignedBigInteger('candidate_id')->nullable();
                $t->foreign('candidate_id')->references('id')->on('hr_candidates')->restrictOnDelete();
                $t->string('kind', 20);
                $t->string('status', 20)->default('draft');
                $t->json('proposal');
                $t->json('context')->nullable();
                $t->text('reason');
                $t->text('review_note')->nullable();
                $t->unsignedBigInteger('revision')->default(1);
                $t->unsignedInteger('submission_version')->default(0);
                $legacy($t, 'created_by_user_id', 'users', 'user_id', false);
                $legacy($t, 'reviewed_by_user_id', 'users', 'user_id');
                $t->dateTime('submitted_at')->nullable();
                $t->dateTime('reviewed_at')->nullable();
                $t->dateTime('materialized_at')->nullable();
                $t->timestamps();
                $t->index(['status', 'submitted_at', 'id']);
            });
        }
        if (! Schema::hasTable('hr_employment_relationships')) {
            Schema::create('hr_employment_relationships', function (Blueprint $t) use ($legacy): void {
                $t->engine = 'InnoDB';
                $t->bigIncrements('id');
                $legacy($t, 'employee_id', 'employees', 'employee_id', false);
                $t->unsignedBigInteger('request_id')->nullable()->unique();
                $t->foreign('request_id')->references('id')->on('hr_relationship_requests')->restrictOnDelete();
                $t->unsignedBigInteger('need_item_id')->nullable();
                $t->foreign('need_item_id')->references('id')->on('hr_staffing_need_items')->restrictOnDelete();
                $t->string('source', 30);
                $t->string('body', 20);
                $t->string('relationship_type', 30);
                $t->string('work_mode', 10);
                $t->date('starts_on');
                $t->date('ends_on')->nullable();
                $t->date('superseded_from')->nullable();
                $t->unsignedBigInteger('predecessor_id')->nullable();
                $t->foreign('predecessor_id')->references('id')->on('hr_employment_relationships')->restrictOnDelete();
                $legacy($t, 'position_id', 'positions', 'position_id');
                $t->string('job_title', 200);
                $legacy($t, 'college_id', 'colleges', 'college_id');
                $legacy($t, 'organizational_unit_id', 'organizational_units', 'organizational_unit_id', false);
                $legacy($t, 'recorded_by_user_id', 'users', 'user_id', false);
                $t->text('notes')->nullable();
                $t->timestamps();
                $t->index(['employee_id', 'starts_on', 'ends_on']);
            });
        }
        if (! Schema::hasTable('hr_workforce_events')) {
            Schema::create('hr_workforce_events', function (Blueprint $t) use ($legacy): void {
                $t->engine = 'InnoDB';
                $t->bigIncrements('id');
                $t->string('subject_type', 30);
                $t->unsignedBigInteger('subject_id');
                $t->string('action', 40);
                $legacy($t, 'actor_user_id', 'users', 'user_id', false);
                $t->json('details');
                $t->dateTime('created_at');
                $t->index(['subject_type', 'subject_id', 'id']);
            });
        }
        if (Schema::hasTable('payroll_employees') && ! Schema::hasColumn('payroll_employees', 'employee_id')) {
            Schema::table('payroll_employees', function (Blueprint $t) use ($legacy): void {
                $legacy($t, 'employee_id', 'employees', 'employee_id');
                $t->unique('employee_id');
                $t->unsignedBigInteger('hr_revision_at_link')->nullable();
                $t->string('hr_body_at_link', 20)->nullable();
            });
        }
        // Every writer, including legacy CRUD/query-builder writes, advances the revision (ABA-safe).
        if (DB::getDriverName() === 'mysql') {
            if (! DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', 'employees_hr_revision')->exists()) {
                DB::unprepared('CREATE TRIGGER employees_hr_revision BEFORE UPDATE ON employees FOR EACH ROW SET NEW.hr_revision = OLD.hr_revision + 1');
            }
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('CREATE TRIGGER IF NOT EXISTS employees_hr_revision AFTER UPDATE ON employees WHEN NEW.hr_revision = OLD.hr_revision BEGIN UPDATE employees SET hr_revision = OLD.hr_revision + 1 WHERE employee_id = NEW.employee_id; END');
        } else {
            throw new RuntimeException('HR migration supports MariaDB/MySQL and SQLite only.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('HR rollback refused: retain employment, recruitment, payroll linkage and audit history. Restore a verified backup only during maintenance.');
    }
};
