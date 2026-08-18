<?php

/**
 * NOTE: This migration documents a table that was created directly via
 * SQL against the production database. Do NOT run this migration.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ministry_placement_records', function (Blueprint $table): void {
            $table->id('placement_record_id');
            $table->foreignId('batch_id')->constrained('ministry_placement_batches', 'batch_id');
            $table->unsignedInteger('row_number')->nullable();
            $table->string('national_civil_id', 50)->nullable();
            $table->string('subscription_number', 100)->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('father_name', 100)->nullable();
            $table->string('mother_name', 100)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('nationality', 100)->nullable();
            $table->string('phone_number', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('certificate_type', 150)->nullable();
            $table->string('certificate_source_country', 150)->nullable();
            $table->string('certificate_grant_year', 20)->nullable();
            $table->string('directorate', 150)->nullable();
            $table->decimal('total_score', 8, 2)->nullable();
            $table->decimal('max_total_score', 8, 2)->nullable();
            $table->text('accepted_preference_text')->nullable();
            $table->foreignId('matched_academic_program_id')->nullable()->constrained('academic_programs', 'academic_program_id');
            $table->string('track', 100)->nullable();
            $table->string('placement_round_name', 150)->nullable();
            $table->string('registration_type', 100)->nullable();
            $table->boolean('is_faculty_member_child')->default(false);
            $table->boolean('has_academic_sequence')->default(false);
            $table->foreignId('applicant_id')->nullable()->constrained('applicants', 'applicant_id');
            $table->string('processing_status', 50)->default('imported');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ministry_placement_records');
    }
};
