<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offering_proposals', function (Blueprint $table): void {
            $table->increments('offering_proposal_id');
            $table->unsignedInteger('academic_year_id');
            $table->unsignedInteger('semester_id');
            $table->unsignedInteger('department_id');
            $table->unsignedInteger('submitted_by_user_id');
            $table->string('status', 30);
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('decided_by_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['department_id', 'academic_year_id', 'semester_id'], 'idx_offering_proposals_term');
        });

        Schema::create('offering_proposal_items', function (Blueprint $table): void {
            $table->increments('offering_proposal_item_id');
            $table->unsignedInteger('offering_proposal_id');
            $table->unsignedInteger('course_id');
            $table->unsignedInteger('academic_program_id')->nullable();
            $table->string('course_type', 30);
            $table->unsignedInteger('faculty_member_id')->nullable();
            $table->boolean('instructor_exception')->default(false);
            $table->string('exception_reason')->nullable();
            $table->unsignedInteger('capacity')->default(40);
            $table->timestamps();

            $table->foreign('offering_proposal_id', 'fk_proposal_items_proposal')
                ->references('offering_proposal_id')
                ->on('offering_proposals')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offering_proposal_items');
        Schema::dropIfExists('offering_proposals');
    }
};
