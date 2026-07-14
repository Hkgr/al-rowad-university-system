<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_offering_instructors', function (Blueprint $table): void {
            $table->increments('course_offering_instructor_id');
            $table->unsignedInteger('course_offering_id');
            $table->unsignedInteger('faculty_member_id');
            $table->string('instructor_role', 50)->default('instructor');
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['course_offering_id', 'faculty_member_id'], 'uq_course_offering_instructor');
            $table->foreign('course_offering_id', 'fk_coi_offering')
                ->references('course_offering_id')
                ->on('course_offerings')
                ->cascadeOnDelete();
            $table->foreign('faculty_member_id', 'fk_coi_faculty')
                ->references('faculty_member_id')
                ->on('faculty_members');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_offering_instructors');
    }
};
