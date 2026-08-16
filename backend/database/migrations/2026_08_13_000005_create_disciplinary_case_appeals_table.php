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
        Schema::create('disciplinary_case_appeals', function (Blueprint $table): void {
            $table->id('appeal_id');
            $table->foreignId('case_id')->constrained('student_disciplinary_cases', 'case_id');
            $table->timestamp('submitted_at')->nullable();
            $table->text('appeal_reason');
            $table->foreignId('appeal_status_id')->constrained('appeal_statuses', 'appeal_status_id');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users', 'user_id');
            $table->date('decision_date')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_case_appeals');
    }
};
