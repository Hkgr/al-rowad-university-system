<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE university_email_operations MODIFY kind ENUM('create','reset','password_reset','suspend','activate','link') NOT NULL");
        }
        Schema::table('university_email_operations', function (Blueprint $t): void {
            $t->string('reason', 500)->nullable();
            $t->json('before_snapshot')->nullable();
        });
        Schema::table('student_university_emails', function (Blueprint $t): void {
            $t->enum('linkage_origin', ['created', 'pending_link', 'linked'])->default('created');
            $t->json('remote_snapshot')->nullable();
            $t->timestamp('remote_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Account management rollback refused: preserve existing accounts and operation history.');
    }
};
