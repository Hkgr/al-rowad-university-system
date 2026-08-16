<?php

/**
 * NOTE: This migration documents a table that was created directly via
 * SQL against the production database. Do NOT run this migration.
 * It exists for schema version-control and local/testing environments only.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_disciplinary_cases', function (Blueprint $table): void {
            $table->id('case_id');
            $table->foreignId('student_id')->constrained('students', 'student_id');
            $table->foreignId('violation_type_id')->constrained('disciplinary_violation_types', 'violation_type_id');
            $table->foreignId('trigger_course_offering_id')->nullable()->constrained('course_offerings', 'course_offering_id');
            $table->text('violation_description');
            $table->date('violation_date');
            $table->foreignId('reported_by_user_id')->nullable()->constrained('users', 'user_id');
            $table->string('investigation_status', 50)->nullable();
            $table->date('investigation_date')->nullable();
            $table->text('investigation_notes')->nullable();
            $table->string('decided_by_authority', 80);
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users', 'user_id');
            $table->string('decision_number', 80)->nullable();
            $table->date('decision_date');
            $table->foreignId('penalty_type_id')->constrained('disciplinary_penalty_types', 'penalty_type_id');
            $table->date('penalty_start_date')->nullable();
            $table->date('penalty_end_date')->nullable();
            $table->boolean('is_in_absentia')->default(false);
            $table->timestamp('guardian_notified_at')->nullable();
            $table->string('case_status', 50)->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_disciplinary_cases');
    }
};
