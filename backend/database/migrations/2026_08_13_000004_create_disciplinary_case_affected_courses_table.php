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
        Schema::create('disciplinary_case_affected_courses', function (Blueprint $table): void {
            $table->id('affected_course_id');
            $table->foreignId('case_id')->constrained('student_disciplinary_cases', 'case_id');
            $table->foreignId('course_offering_id')->constrained('course_offerings', 'course_offering_id');
            $table->decimal('previous_theoretical_mark', 5, 2)->nullable();
            $table->decimal('previous_practical_mark', 5, 2)->nullable();
            $table->decimal('previous_coursework_mark', 5, 2)->nullable();
            $table->decimal('previous_final_mark', 5, 2)->nullable();
            $table->foreignId('previous_result_status_id')->nullable()->constrained('result_statuses', 'result_status_id');
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_case_affected_courses');
    }
};
