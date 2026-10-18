<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('university_email_operations', function (Blueprint $t): void {
            $t->uuid('operation_id')->primary();
            $t->unsignedBigInteger('university_email_id');
            $t->enum('kind', ['create', 'reset']);
            $t->unsignedTinyInteger('creation_slot')->nullable();
            $t->unsignedTinyInteger('active_slot')->nullable();
            $t->enum('status', ['prepared', 'preflight', 'in_progress', 'uncertain', 'confirmed', 'conflict', 'failed']);
            $t->unsignedInteger('draft_revision');
            $t->string('email_address', 254);
            $t->unsignedInteger('quota_mb');
            $t->unsignedInteger('generation')->default(1);
            $t->integer('initiated_by_user_id');
            $t->integer('issued_by_user_id');
            $t->timestamp('write_started_at')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->string('failure_code', 80)->nullable();
            $t->timestamps();
            $t->unique(['university_email_id', 'creation_slot'], 'ue_operation_creation_unique');
            $t->unique(['university_email_id', 'active_slot'], 'ue_operation_active_unique');
            $t->index(['status', 'updated_at'], 'ue_operation_reconcile_index');
            $t->foreign('university_email_id')->references('university_email_id')->on('student_university_emails')->restrictOnDelete();
            foreach (['initiated_by_user_id', 'issued_by_user_id'] as $column) $t->foreign($column)->references('user_id')->on('users')->restrictOnDelete();
        });
        Schema::create('university_email_receipts', function (Blueprint $t): void {
            $t->uuid('receipt_id')->primary();
            $t->unsignedBigInteger('university_email_id');
            $t->uuid('credential_operation_id');
            $t->integer('issued_by_user_id');
            $t->timestamp('issued_at');
            $t->unique(['university_email_id', 'credential_operation_id', 'issued_by_user_id'], 'ue_receipt_issue_unique');
            $t->foreign('university_email_id')->references('university_email_id')->on('student_university_emails')->restrictOnDelete();
            $t->foreign('credential_operation_id')->references('operation_id')->on('university_email_operations')->restrictOnDelete();
            $t->foreign('issued_by_user_id')->references('user_id')->on('users')->restrictOnDelete();
        });
        Schema::table('student_university_emails', function (Blueprint $t): void {
            $t->uuid('creation_operation_id')->nullable();
            $t->uuid('credential_operation_id')->nullable();
            foreach (['creation_operation_id', 'credential_operation_id'] as $column) $t->foreign($column)->references('operation_id')->on('university_email_operations')->restrictOnDelete();
        });
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE university_email_operations ADD CONSTRAINT ue_operation_slots_check CHECK ((creation_slot IS NULL OR (creation_slot = 1 AND kind = 'create')) AND (active_slot IS NULL OR active_slot = 1))");
        }
    }

    public function down(): void
    {
        if (DB::table('university_email_operations')->exists() || DB::table('university_email_receipts')->exists()) {
            throw new RuntimeException('University email Phase 2 rollback refused: operation/receipt history must be preserved.');
        }
        Schema::table('student_university_emails', function (Blueprint $t): void {
            foreach (['creation_operation_id', 'credential_operation_id'] as $column) $t->dropForeign([$column]);
            $t->dropColumn(['creation_operation_id', 'credential_operation_id']);
        });
        Schema::drop('university_email_receipts');
        Schema::drop('university_email_operations');
    }
};
