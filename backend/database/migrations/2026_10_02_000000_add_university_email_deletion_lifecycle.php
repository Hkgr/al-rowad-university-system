<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        // Extend, never replace, existing values. All schema changes live in this migration.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE university_email_operations MODIFY kind ENUM('create','reset','password_reset','suspend','activate','link','delete') NOT NULL");
            DB::statement("ALTER TABLE student_university_emails MODIFY provisioning_status ENUM('draft','created','deleted') NOT NULL DEFAULT 'draft'");
        }
        Schema::table('student_university_emails', function (Blueprint $t): void {
            // A monotonic revision boundary, not a timestamp/value fingerprint, separates cycles.
            $t->unsignedInteger('lifecycle_revision')->default(1);
            $t->timestamp('deleted_at')->nullable();
            $t->integer('deleted_by_user_id')->nullable();
            $t->foreign('deleted_by_user_id')->references('user_id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Deletion lifecycle rollback refused: preserve deleted accounts, enum values and operation/receipt history.');
    }
};
