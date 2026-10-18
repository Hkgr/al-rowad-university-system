<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use App\Services\DataScopeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DataScopeStudentSelfScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('students', function (Blueprint $table): void {
            $table->integer('student_id')->primary();
            $table->integer('academic_program_id');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('academic_programs', function (Blueprint $table): void {
            $table->integer('academic_program_id')->primary();
            $table->integer('department_id');
        });
        Schema::create('departments', function (Blueprint $table): void {
            $table->integer('department_id')->primary();
            $table->integer('college_id');
        });
        Schema::create('student_course_registrations', function (Blueprint $table): void {
            $table->integer('student_course_registration_id')->primary();
            $table->integer('student_id');
            $table->integer('course_offering_id');
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->integer('role_id')->primary();
            $table->string('role_code');
            $table->boolean('is_active');
        });
        Schema::create('user_roles', function (Blueprint $table): void {
            $table->integer('user_role_id')->primary();
            $table->integer('user_id');
            $table->integer('role_id');
            $table->boolean('is_active');
        });
        Schema::create('user_access_scopes', function (Blueprint $table): void {
            $table->integer('user_access_scope_id')->primary();
            $table->integer('user_id');
            $table->string('scope_type');
            $table->integer('scope_id');
            $table->boolean('is_active');
        });
        DB::table('students')->insert([
            ['student_id' => 1, 'academic_program_id' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['student_id' => 2, 'academic_program_id' => 10, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_linked_student_is_visible_without_broadening_other_scope_paths(): void
    {
        $linked = (new User)->forceFill(['user_id' => 7, 'student_id' => 2]);
        $linked->exists = true;
        $unlinked = (new User)->forceFill(['user_id' => 8, 'student_id' => null]);
        $unlinked->exists = true;

        $scope = app(DataScopeService::class);
        self::assertSame([2], $scope->scopeStudents(Student::query(), $linked)->pluck('student_id')->all());
        self::assertSame([], $scope->scopeStudents(Student::query(), $unlinked)->pluck('student_id')->all());
    }
}
