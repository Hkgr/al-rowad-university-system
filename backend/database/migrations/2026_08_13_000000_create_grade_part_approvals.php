<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('student_course_results', function (Blueprint $table): void {
            $table->decimal('theoretical_total', 5, 2)->nullable()->default(null)->change();
            $table->decimal('practical_total', 5, 2)->nullable()->default(null)->change();
            $table->decimal('final_mark', 5, 2)->nullable()->default(null)->change();
        });
        Schema::create('grade_part_approvals', function (Blueprint $table): void {
            $table->id('grade_part_approval_id'); $table->integer('course_offering_id');
            $table->enum('component_type', ['practical', 'theoretical']); $table->enum('status', ['draft', 'submitted', 'returned', 'approved'])->default('draft');
            $table->unsignedInteger('submission_version')->default(0); $table->integer('submitted_by_user_id')->nullable(); $table->dateTime('submitted_at')->nullable();
            $table->integer('reviewed_by_user_id')->nullable(); $table->dateTime('reviewed_at')->nullable(); $table->text('review_notes')->nullable(); $table->timestamps();
            $table->unique(['course_offering_id', 'component_type'], 'uq_grade_part_current');
            $table->foreign('course_offering_id')->references('course_offering_id')->on('course_offerings');
            $table->foreign('submitted_by_user_id')->references('user_id')->on('users'); $table->foreign('reviewed_by_user_id')->references('user_id')->on('users');
        });
        Schema::create('grade_part_approval_events', function (Blueprint $table): void {
            $table->id('grade_part_approval_event_id'); $table->unsignedBigInteger('grade_part_approval_id'); $table->unsignedInteger('submission_version');
            $table->string('action', 30); $table->json('old_values')->nullable(); $table->json('new_values'); $table->integer('performed_by_user_id'); $table->timestamp('performed_at')->useCurrent();
            $table->foreign('grade_part_approval_id')->references('grade_part_approval_id')->on('grade_part_approvals'); $table->foreign('performed_by_user_id')->references('user_id')->on('users');
        });
    }
    public function down(): void { Schema::dropIfExists('grade_part_approval_events'); Schema::dropIfExists('grade_part_approvals'); }
};
