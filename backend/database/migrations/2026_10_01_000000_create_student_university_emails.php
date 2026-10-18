<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_university_emails', function (Blueprint $table): void {
            $table->bigIncrements('university_email_id');
            // Production students/users keys are signed INT, not unsigned BIGINT.
            $table->integer('student_id')->unique();
            $table->string('english_first_name', 64);
            $table->string('email_address', 254)->unique();
            $table->unsignedInteger('quota_mb');
            $table->enum('provisioning_status', ['draft', 'created'])->default('draft');
            $table->enum('handover_status', ['not_delivered', 'delivered'])->default('not_delivered');
            $table->unsignedInteger('revision')->default(1);
            $table->integer('created_by_user_id');
            $table->integer('updated_by_user_id');
            $table->timestamps();
            $table->foreign('student_id')->references('student_id')->on('students')->restrictOnDelete();
            $table->foreign('created_by_user_id')->references('user_id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by_user_id')->references('user_id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Rollback must never erase saved preparation/history.
        if (Schema::hasTable('student_university_emails') && DB::table('student_university_emails')->exists()) {
            throw new RuntimeException('University email rollback refused: preserve existing records.');
        }
        Schema::dropIfExists('student_university_emails');
    }
};
