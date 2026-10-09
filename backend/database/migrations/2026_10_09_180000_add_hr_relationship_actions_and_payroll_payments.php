<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('HR relationship actions and payment ledger support MariaDB/MySQL and SQLite only.');
        }
        if (! Schema::hasTable('hr_employment_relationships') || ! Schema::hasColumn('payroll_employees', 'employee_id') || ! Schema::hasColumns('employees', ['hr_revision', 'hr_body'])) {
            throw new RuntimeException('Install the HR identity-link schema first. No historical payment or relationship backfill is permitted.');
        }
        if (DB::getDriverName() === 'mysql') {
            foreach (['employees', 'users', 'payroll_employees', 'hr_employment_relationships', 'hr_relationship_requests'] as $table) {
                if (DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->value('ENGINE') !== 'InnoDB') {
                    throw new RuntimeException('Existing InnoDB parent required: '.$table);
                }
            }
        }
        if (Schema::hasColumn('hr_employment_relationships', 'revision') && ! Schema::hasColumns('hr_employment_relationships', ['cancelled_from', 'cancelled_at', 'cancelled_by_user_id', 'approval_origin_request_id'])) {
            throw new RuntimeException('Partial relationship-action schema: stop and review before resuming migration. No historical fields will be guessed.');
        }
        if (! Schema::hasColumn('hr_employment_relationships', 'revision')) {
            Schema::table('hr_employment_relationships', function (Blueprint $t): void {
                $t->unsignedBigInteger('revision')->default(1);
                $t->date('cancelled_from')->nullable();
                $t->dateTime('cancelled_at')->nullable();
                $t->integer('cancelled_by_user_id')->nullable();
                $t->foreign('cancelled_by_user_id')->references('user_id')->on('users')->restrictOnDelete();
                $t->unsignedBigInteger('approval_origin_request_id')->nullable();
                $t->foreign('approval_origin_request_id')->references('id')->on('hr_relationship_requests')->restrictOnDelete();
            });
        }
        if (! Schema::hasTable('payroll_payments')) {
            Schema::create('payroll_payments', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->bigIncrements('id');
                $t->uuid('request_id')->unique();
                $t->char('payload_hash', 64);
                $t->unsignedBigInteger('payroll_employee_id');
                $t->foreign('payroll_employee_id')->references('id')->on('payroll_employees')->restrictOnDelete();
                $t->integer('employee_id');
                $t->foreign('employee_id')->references('employee_id')->on('employees')->restrictOnDelete();
                $t->string('period', 7); // Explicit salary period, not inferred from the payment date.
                $t->date('paid_on');
                $t->unsignedBigInteger('amount_cents'); // Exact hundredths; no floating point storage or totals.
                $t->char('currency', 3)->default('SYP');
                $t->string('reference', 120);
                $t->unsignedTinyInteger('reference_slot')->nullable()->default(1);
                $t->unique(['payroll_employee_id', 'reference', 'reference_slot'], 'payroll_payment_reference');
                $t->text('reason');
                $t->json('identity_snapshot');
                $t->enum('status', ['paid', 'received', 'voided'])->default('paid');
                $t->unsignedBigInteger('revision')->default(1);
                $t->integer('recorded_by_user_id');
                $t->foreign('recorded_by_user_id')->references('user_id')->on('users')->restrictOnDelete();
                $t->date('received_on')->nullable();
                $t->dateTime('received_at')->nullable();
                $t->integer('received_by_user_id')->nullable();
                $t->foreign('received_by_user_id')->references('user_id')->on('users')->restrictOnDelete();
                $t->text('receipt_evidence')->nullable();
                $t->dateTime('voided_at')->nullable();
                $t->integer('voided_by_user_id')->nullable();
                $t->foreign('voided_by_user_id')->references('user_id')->on('users')->restrictOnDelete();
                $t->text('void_reason')->nullable();
                $t->timestamps();
                $t->index(['employee_id', 'paid_on', 'id']);
                $t->index(['payroll_employee_id', 'status', 'id']);
            });
        }
        if (! Schema::hasTable('payroll_payment_events')) {
            Schema::create('payroll_payment_events', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->bigIncrements('id');
                $t->unsignedBigInteger('payment_id');
                $t->foreign('payment_id')->references('id')->on('payroll_payments')->restrictOnDelete();
                $t->string('action', 30);
                $t->integer('actor_user_id');
                $t->foreign('actor_user_id')->references('user_id')->on('users')->restrictOnDelete();
                $t->json('details');
                $t->dateTime('created_at');
                $t->index(['payment_id', 'id']);
            });
        }
        if (DB::getDriverName() === 'mysql') {
            if (! DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', 'hr_relationship_revision')->exists()) {
                DB::unprepared('CREATE TRIGGER hr_relationship_revision BEFORE UPDATE ON hr_employment_relationships FOR EACH ROW SET NEW.revision = OLD.revision + 1');
            }
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('CREATE TRIGGER IF NOT EXISTS hr_relationship_revision AFTER UPDATE ON hr_employment_relationships WHEN NEW.revision = OLD.revision BEGIN UPDATE hr_employment_relationships SET revision = OLD.revision + 1 WHERE id = NEW.id; END');
        } else {
            throw new RuntimeException('Relationship revisions require MariaDB/MySQL or SQLite.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Rollback refused: retain payroll disbursement/receipt and relationship correction/cancellation history.');
    }
};
