<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Services\GradeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SemesterRegistrationEligibilityPhase3GradeBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->schema();
        $this->seedReferences();
    }

    public function test_only_latest_authoritative_approved_attempt_affects_cgpa_and_passed_courses(): void
    {
        $this->attempt(1, 1, 90, 'passed', 'approved');
        DB::table('grade_approvals')->insert([
            'grade_approval_id' => 2,
            'course_offering_id' => 1,
            'approval_status_id' => 2,
        ]);

        $metrics = app(GradeService::class)->officialCumulativeMetrics(Student::query()->findOrFail(1));

        self::assertNull($metrics['cumulative_gpa']);
        self::assertSame([], $metrics['official_completed_courses']);
    }

    public function test_approved_pass_is_the_only_canonical_passed_course_boundary(): void
    {
        $this->attempt(1, 1, 80, 'passed', 'approved');
        $this->attempt(2, 2, 48, 'failed', 'approved');
        $this->attempt(3, 3, 80, 'passed', 'returned');

        $metrics = app(GradeService::class)->officialCumulativeMetrics(Student::query()->findOrFail(1));

        self::assertSame([1], collect($metrics['official_completed_courses'])->pluck('course_id')->all());
        self::assertSame(2, $metrics['official_attempts_count'] ?? $metrics['summary']['approved_courses_count']);
        self::assertSame(1, $metrics['failed_courses_count']);
    }

    public function test_highest_attempt_only_cgpa_semantics_are_reused_for_repeated_course(): void
    {
        $this->attempt(1, 1, 60, 'passed', 'approved');
        $this->attempt(2, 1, 90, 'passed', 'approved');

        $metrics = app(GradeService::class)->officialCumulativeMetrics(Student::query()->findOrFail(1));

        self::assertSame(4.0, $metrics['cumulative_gpa']);
        self::assertSame('highest_attempt_only', $metrics['repeated_courses_handling']);
        self::assertSame([1], collect($metrics['official_completed_courses'])->pluck('course_id')->all());
    }

    private function attempt(
        int $offeringId,
        int $courseId,
        float $finalMark,
        string $resultStatus,
        string $approvalStatus,
    ): void {
        DB::table('course_offerings')->insert([
            'course_offering_id' => $offeringId,
            'course_id' => $courseId,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'status' => 'closed',
        ]);
        DB::table('student_course_registrations')->insert([
            'student_course_registration_id' => $offeringId,
            'student_id' => 1,
            'course_offering_id' => $offeringId,
            'registration_status_id' => 1,
        ]);
        DB::table('student_course_results')->insert([
            'student_course_result_id' => $offeringId,
            'student_course_registration_id' => $offeringId,
            'theoretical_total' => $finalMark,
            'practical_total' => null,
            'final_mark' => $finalMark,
            'result_status_id' => $this->resultStatusId($resultStatus),
            'is_deprived' => $resultStatus === 'deprived',
        ]);
        DB::table('grade_approvals')->insert([
            'grade_approval_id' => $offeringId,
            'course_offering_id' => $offeringId,
            'approval_status_id' => $this->approvalStatusId($approvalStatus),
        ]);
    }

    private function resultStatusId(string $code): int
    {
        return (int) DB::table('result_statuses')->where('status_code', $code)->value('result_status_id');
    }

    private function approvalStatusId(string $code): int
    {
        return (int) DB::table('approval_statuses')->where('status_code', $code)->value('approval_status_id');
    }

    private function seedReferences(): void
    {
        DB::table('students')->insert(['student_id' => 1]);
        DB::table('academic_years')->insert(['academic_year_id' => 1, 'start_date' => '2026-09-01']);
        DB::table('semesters')->insert(['semester_id' => 1, 'semester_order' => 1]);
        DB::table('registration_statuses')->insert(['registration_status_id' => 1, 'status_code' => 'completed']);
        DB::table('result_statuses')->insert([
            ['result_status_id' => 1, 'status_code' => 'passed'],
            ['result_status_id' => 2, 'status_code' => 'failed'],
            ['result_status_id' => 3, 'status_code' => 'incomplete'],
            ['result_status_id' => 4, 'status_code' => 'deprived'],
        ]);
        DB::table('approval_statuses')->insert([
            ['approval_status_id' => 1, 'status_code' => 'approved'],
            ['approval_status_id' => 2, 'status_code' => 'pending'],
            ['approval_status_id' => 3, 'status_code' => 'returned'],
        ]);
        DB::table('courses')->insert([
            ['course_id' => 1, 'course_code' => 'C1', 'course_name' => 'Course 1', 'credit_hours' => 3, 'theoretical_hours' => 3, 'practical_hours' => 0],
            ['course_id' => 2, 'course_code' => 'C2', 'course_name' => 'Course 2', 'credit_hours' => 3, 'theoretical_hours' => 3, 'practical_hours' => 0],
            ['course_id' => 3, 'course_code' => 'C3', 'course_name' => 'Course 3', 'credit_hours' => 3, 'theoretical_hours' => 3, 'practical_hours' => 0],
        ]);
    }

    private function schema(): void
    {
        Schema::create('students', function (Blueprint $table): void {
            $table->increments('student_id');
            $table->integer('academic_program_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('academic_years', function (Blueprint $table): void {
            $table->increments('academic_year_id');
            $table->date('start_date')->nullable();
        });
        Schema::create('semesters', function (Blueprint $table): void {
            $table->increments('semester_id');
            $table->integer('semester_order')->nullable();
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->increments('course_id');
            $table->string('course_code')->nullable();
            $table->string('course_name')->nullable();
            $table->integer('credit_hours')->default(0);
            $table->decimal('theoretical_hours', 5, 2)->default(0);
            $table->decimal('practical_hours', 5, 2)->default(0);
        });
        Schema::create('course_offerings', function (Blueprint $table): void {
            $table->increments('course_offering_id');
            $table->integer('course_id');
            $table->integer('academic_year_id');
            $table->integer('semester_id');
            $table->string('status');
        });
        Schema::create('registration_statuses', function (Blueprint $table): void {
            $table->increments('registration_status_id');
            $table->string('status_code');
        });
        Schema::create('result_statuses', function (Blueprint $table): void {
            $table->increments('result_status_id');
            $table->string('status_code');
        });
        Schema::create('approval_statuses', function (Blueprint $table): void {
            $table->increments('approval_status_id');
            $table->string('status_code');
        });
        Schema::create('student_course_registrations', function (Blueprint $table): void {
            $table->increments('student_course_registration_id');
            $table->integer('student_id');
            $table->integer('course_offering_id');
            $table->integer('registration_status_id');
            $table->integer('result_status_id')->nullable();
            $table->timestamps();
        });
        Schema::create('student_course_results', function (Blueprint $table): void {
            $table->increments('student_course_result_id');
            $table->integer('student_course_registration_id');
            $table->decimal('theoretical_total', 6, 2)->nullable();
            $table->decimal('practical_total', 6, 2)->nullable();
            $table->decimal('final_mark', 6, 2)->nullable();
            $table->integer('result_status_id');
            $table->boolean('is_deprived')->default(false);
            $table->timestamps();
        });
        Schema::create('grade_approvals', function (Blueprint $table): void {
            $table->increments('grade_approval_id');
            $table->integer('course_offering_id');
            $table->integer('approval_status_id');
            $table->timestamps();
        });
        Schema::create('grade_components', function (Blueprint $table): void {
            $table->increments('grade_component_id');
            $table->integer('course_offering_id');
            $table->string('component_type');
            $table->boolean('is_required')->default(true);
        });
    }
}
