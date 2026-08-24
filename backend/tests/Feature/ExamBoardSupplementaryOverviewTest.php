<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\SupplementaryExamOverviewController;
use App\Models\User;
use App\Models\SupplementaryExamGradeSubmission;
use App\Services\DataScopeService;
use App\Services\GradeService;
use App\Services\SupplementaryExamGradingService;
use App\Services\SupplementaryExamOccurrenceService;
use App\Services\SupplementaryExamOverviewService;
use App\Support\AcademicCalendarPolicyResult;
use App\Support\AcademicCalendarPolicyStatus;
use App\Support\SupplementaryExamOccurrenceSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ExamBoardSupplementaryOverviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->schema();
        $this->seedReferenceData();
    }

    public function test_announced_period_keeps_zero_registration_offering_visible(): void
    {
        $payload = $this->service()->overview($this->actor(), perPage: 1);

        self::assertSame('announced', $payload['selected_period']['status']);
        self::assertSame('open', $payload['selected_period']['supplementary_exam_occurrence']['status']);
        self::assertCount(1, $payload['offerings']);
        self::assertSame(0, $payload['offerings'][0]['counts']['registered']);
        self::assertSame(0, $payload['summary']['registered_students_count']);
        self::assertSame([], $payload['registrations']['data']);
        self::assertSame('current', $payload['stage']['steps'][0]['state']);
    }

    public function test_roster_is_current_scoped_and_summary_is_independent_of_page(): void
    {
        $this->registration(1, 1, 1, 'registered');
        $this->registration(2, 2, 1, 'registered');
        $this->registration(3, 3, null, 'cancelled');

        $payload = $this->service()->overview($this->actor(), perPage: 1);

        self::assertCount(1, $payload['registrations']['data']);
        self::assertSame(2, $payload['registrations']['meta']['total']);
        self::assertSame(2, $payload['registrations']['meta']['last_page']);
        self::assertSame(2, $payload['summary']['registered_students_count']);
        self::assertSame(2, $payload['offerings'][0]['counts']['registered']);
        self::assertSame('STU-1', $payload['registrations']['data'][0]['student']['student_number']);
    }

    public function test_program_and_student_scope_excludes_other_program_data(): void
    {
        DB::table('supplementary_exam_offerings')->insert([
            'supplementary_exam_offering_id' => 2,
            'supplementary_exam_period_id' => 1,
            'academic_program_id' => 2,
            'course_id' => 2,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->registration(1, 1, 1, 'registered');
        $this->registration(2, 2, 1, 'registered', 2, 2);

        $payload = $this->service(true)->overview($this->actor(), perPage: 20);

        self::assertCount(1, $payload['offerings']);
        self::assertSame(1, $payload['offerings'][0]['supplementary_exam_offering_id']);
        self::assertCount(1, $payload['registrations']['data']);
        self::assertSame(1, $payload['summary']['registered_students_count']);
    }

    public function test_missing_view_permission_is_forbidden_before_overview_evaluation(): void
    {
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->shouldReceive('effectivePermissions')->once()->andReturn(collect());
        $actor->shouldReceive('hasRoleCode')->with('super_admin')->once()->andReturnFalse();
        $overview = Mockery::mock(SupplementaryExamOverviewService::class);
        $overview->shouldNotReceive('overview');
        $request = Request::create('/api/v1/exams/supplementary-overview', 'GET');
        $request->setUserResolver(fn (): User => $actor);

        $this->expectException(HttpException::class);
        (new SupplementaryExamOverviewController())($request, $overview);
    }

    public function test_view_permission_returns_the_read_only_overview_contract(): void
    {
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->shouldReceive('effectivePermissions')->once()->andReturn(collect(['supplementary_exams.registrations.view']));
        $overview = Mockery::mock(SupplementaryExamOverviewService::class);
        $overview->shouldReceive('overview')->once()->with($actor, 1, null, null, 20)->andReturn(['selected_period' => ['supplementary_exam_period_id' => 1]]);
        $request = Request::create('/api/v1/exams/supplementary-overview?period_id=1&per_page=20', 'GET');
        $request->setUserResolver(fn (): User => $actor);

        $response = (new SupplementaryExamOverviewController())($request, $overview);

        self::assertSame(200, $response->status());
        self::assertSame(1, $response->getData(true)['data']['selected_period']['supplementary_exam_period_id']);
    }

    public function test_latest_submission_published_and_materialized_counts_stay_distinct(): void
    {
        $this->registration(1, 1, 1, 'registered');
        DB::table('supplementary_exam_grade_results')->insert([
            'supplementary_exam_grade_result_id' => 1,
            'supplementary_exam_registration_id' => 1,
            'supplementary_exam_offering_id' => 1,
            'theoretical_mark' => 55,
            'status' => 'published',
            'submission_version' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('supplementary_exam_grade_submissions')->insert([
            'supplementary_exam_grade_submission_id' => 2,
            'supplementary_exam_offering_id' => 1,
            'submission_version' => 2,
            'status' => 'published',
            'published_at' => now(),
        ]);
        DB::table('supplementary_exam_materializations')->insert([
            'supplementary_exam_materialization_id' => 1,
            'supplementary_exam_registration_id' => 1,
            'supplementary_exam_offering_id' => 1,
        ]);
        DB::table('employees')->insert(['employee_id' => 1, 'employee_number' => 'EMP-1', 'first_name' => 'Current', 'last_name' => 'Grader']);
        DB::table('faculty_members')->insert(['faculty_member_id' => 1, 'employee_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('supplementary_exam_grader_assignments')->insert([
            'supplementary_exam_grader_assignment_id' => 1,
            'supplementary_exam_offering_id' => 1,
            'faculty_member_id' => 1,
            'current_slot' => 1,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $latest = new SupplementaryExamGradeSubmission();
        $latest->setRawAttributes([
            'supplementary_exam_grade_submission_id' => 2,
            'supplementary_exam_offering_id' => 1,
            'submission_version' => 2,
            'status' => 'published',
        ]);

        $payload = $this->service(false, collect([1 => $latest]))->overview($this->actor());

        self::assertSame(['registered' => 1, 'graded' => 1, 'published' => 1, 'materialized' => 1], $payload['offerings'][0]['counts']);
        self::assertSame('published', $payload['offerings'][0]['workflow_status']);
        self::assertSame('Current Grader', $payload['offerings'][0]['current_grader']['full_name']);
        self::assertSame(1, $payload['summary']['published_offerings_count']);
        self::assertSame(1, $payload['summary']['materialized_students_count']);
    }

    public function test_explicit_out_of_scope_period_is_not_disclosed(): void
    {
        DB::table('supplementary_exam_periods')->insert([
            'supplementary_exam_period_id' => 2, 'academic_year_id' => 1, 'semester_id' => 1,
            'period_name' => 'Out of scope', 'status' => 'announced', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supplementary_exam_offerings')->insert([
            'supplementary_exam_offering_id' => 2, 'supplementary_exam_period_id' => 2,
            'academic_program_id' => 2, 'course_id' => 2, 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(NotFoundHttpException::class);
        $this->service(true)->overview($this->actor(), periodId: 2);
    }

    public function test_latest_submission_helper_uses_version_then_identity_tiebreak(): void
    {
        DB::table('supplementary_exam_grade_submissions')->insert([
            ['supplementary_exam_grade_submission_id' => 1, 'supplementary_exam_offering_id' => 1, 'submission_version' => 1, 'status' => 'returned'],
            ['supplementary_exam_grade_submission_id' => 2, 'supplementary_exam_offering_id' => 1, 'submission_version' => 2, 'status' => 'submitted'],
            ['supplementary_exam_grade_submission_id' => 3, 'supplementary_exam_offering_id' => 1, 'submission_version' => 2, 'status' => 'approved'],
        ]);
        $grading = new SupplementaryExamGradingService(Mockery::mock(GradeService::class), Mockery::mock(DataScopeService::class));

        $latest = $grading->latestSubmissionsForOfferings(collect([1]));

        self::assertSame(3, (int) $latest->get(1)->getKey());
        self::assertSame('approved', $latest->get(1)->status);
    }

    public function test_query_count_does_not_grow_with_more_offerings(): void
    {
        DB::enableQueryLog();
        $this->service()->overview($this->actor());
        $oneOfferingQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::table('supplementary_exam_offerings')->insert([
            'supplementary_exam_offering_id' => 2, 'supplementary_exam_period_id' => 1,
            'academic_program_id' => 2, 'course_id' => 2, 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::flushQueryLog();
        $this->service()->overview($this->actor());
        $twoOfferingQueries = count(DB::getQueryLog());

        self::assertSame($oneOfferingQueries, $twoOfferingQueries);
    }

    private function service(bool $narrowScope = false, ?Collection $latest = null): SupplementaryExamOverviewService
    {
        $scope = Mockery::mock(DataScopeService::class);
        $scope->shouldReceive('hasActualUniversityScope')->andReturn(! $narrowScope);
        $scope->shouldReceive('scopePrograms')->andReturnUsing(function (Builder $query) use ($narrowScope): Builder {
            return $narrowScope ? $query->whereKey(1) : $query;
        });
        $scope->shouldReceive('scopeStudents')->andReturnUsing(function (Builder $query) use ($narrowScope): Builder {
            return $narrowScope ? $query->where('academic_program_id', 1) : $query;
        });
        $grading = Mockery::mock(SupplementaryExamGradingService::class);
        $grading->shouldReceive('latestSubmissionsForOfferings')->andReturn($latest ?? collect());
        $occurrence = Mockery::mock(SupplementaryExamOccurrenceService::class);
        $occurrence->shouldReceive('snapshotForPeriod')->andReturnUsing(function ($period): SupplementaryExamOccurrenceSnapshot {
            $at = CarbonImmutable::parse('2026-08-25T12:00:00Z');

            return new SupplementaryExamOccurrenceSnapshot(
                (int) $period->getKey(),
                (int) $period->academic_year_id,
                (int) $period->semester_id,
                $at,
                new AcademicCalendarPolicyResult(
                    AcademicCalendarPolicyStatus::OPEN,
                    'supplementary_exams',
                    (int) $period->academic_year_id,
                    (int) $period->semester_id,
                    $at,
                    1,
                ),
            );
        });

        return new SupplementaryExamOverviewService($scope, $grading, $occurrence);
    }

    private function actor(): User
    {
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->setAttribute('user_id', 99);
        $actor->shouldReceive('hasRoleCode')->with('super_admin')->andReturnFalse();
        $actor->shouldReceive('isExamOfficer')->andReturnFalse();
        $actor->shouldReceive('effectivePermissions')->andReturn(collect(['supplementary_exams.registrations.view']));

        return $actor;
    }

    private function registration(
        int $id,
        int $studentId,
        ?int $currentSlot,
        string $status,
        int $offeringId = 1,
        int $programId = 1,
    ): void {
        DB::table('students')->insert([
            'student_id' => $studentId,
            'student_number' => 'STU-'.$studentId,
            'first_name' => 'Student',
            'last_name' => (string) $studentId,
            'academic_program_id' => $programId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('supplementary_exam_registrations')->insert([
            'supplementary_exam_registration_id' => $id,
            'supplementary_exam_offering_id' => $offeringId,
            'student_id' => $studentId,
            'student_course_registration_id' => $id,
            'status' => $status,
            'current_slot' => $currentSlot,
            'eligibility_reason' => 'failed_theoretical',
            'registration_channel' => 'registration_office',
            'registered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedReferenceData(): void
    {
        DB::table('academic_years')->insert(['academic_year_id' => 1, 'year_name' => '2026-2027']);
        DB::table('semesters')->insert(['semester_id' => 1, 'semester_code' => 'summer', 'semester_name' => 'Summer']);
        DB::table('colleges')->insert(['college_id' => 1, 'college_name' => 'College']);
        DB::table('departments')->insert(['department_id' => 1, 'college_id' => 1, 'department_name' => 'Department']);
        DB::table('academic_programs')->insert([
            ['academic_program_id' => 1, 'department_id' => 1, 'program_code' => 'P1', 'program_name' => 'Program 1'],
            ['academic_program_id' => 2, 'department_id' => 1, 'program_code' => 'P2', 'program_name' => 'Program 2'],
        ]);
        DB::table('courses')->insert([
            ['course_id' => 1, 'course_code' => 'C1', 'course_name' => 'Course 1'],
            ['course_id' => 2, 'course_code' => 'C2', 'course_name' => 'Course 2'],
        ]);
        DB::table('supplementary_exam_periods')->insert([
            'supplementary_exam_period_id' => 1,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'period_name' => 'Period 1',
            'status' => 'announced',
            'is_active' => 1,
            'start_date' => '2026-08-20',
            'end_date' => '2026-08-30',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('supplementary_exam_offerings')->insert([
            'supplementary_exam_offering_id' => 1,
            'supplementary_exam_period_id' => 1,
            'academic_program_id' => 1,
            'course_id' => 1,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function schema(): void
    {
        Schema::create('academic_years', fn (Blueprint $table) => $table->integer('academic_year_id')->primary()->nullable(false));
        Schema::table('academic_years', fn (Blueprint $table) => $table->string('year_name')->nullable());
        Schema::create('semesters', function (Blueprint $table): void {
            $table->integer('semester_id')->primary(); $table->string('semester_code')->nullable(); $table->string('semester_name')->nullable();
        });
        Schema::create('colleges', function (Blueprint $table): void { $table->integer('college_id')->primary(); $table->string('college_name')->nullable(); });
        Schema::create('departments', function (Blueprint $table): void { $table->integer('department_id')->primary(); $table->integer('college_id'); $table->string('department_name')->nullable(); });
        Schema::create('academic_programs', function (Blueprint $table): void { $table->integer('academic_program_id')->primary(); $table->integer('department_id'); $table->string('program_code')->nullable(); $table->string('program_name')->nullable(); });
        Schema::create('courses', function (Blueprint $table): void { $table->integer('course_id')->primary(); $table->string('course_code')->nullable(); $table->string('course_name')->nullable(); });
        Schema::create('supplementary_exam_periods', function (Blueprint $table): void {
            $table->integer('supplementary_exam_period_id')->primary(); $table->integer('academic_year_id'); $table->integer('semester_id'); $table->string('period_name')->nullable(); $table->string('status')->nullable(); $table->boolean('is_active')->default(true); $table->date('start_date')->nullable(); $table->date('end_date')->nullable(); $table->text('decision_note')->nullable(); $table->timestamps();
        });
        Schema::create('supplementary_exam_offerings', function (Blueprint $table): void {
            $table->integer('supplementary_exam_offering_id')->primary(); $table->integer('supplementary_exam_period_id'); $table->integer('academic_program_id'); $table->integer('course_id'); $table->string('status'); $table->timestamps();
        });
        Schema::create('course_offerings', function (Blueprint $table): void { $table->integer('course_offering_id')->primary(); $table->integer('academic_year_id')->nullable(); $table->integer('semester_id')->nullable(); $table->timestamps(); });
        Schema::create('supplementary_exam_offering_sources', function (Blueprint $table): void { $table->integer('supplementary_exam_offering_source_id')->primary(); $table->integer('supplementary_exam_offering_id'); $table->integer('course_offering_id'); $table->dateTime('created_at')->nullable(); });
        Schema::create('students', function (Blueprint $table): void { $table->integer('student_id')->primary(); $table->string('student_number')->nullable(); $table->string('first_name')->nullable(); $table->string('last_name')->nullable(); $table->integer('academic_program_id')->nullable(); $table->softDeletes(); $table->timestamps(); });
        Schema::create('supplementary_exam_registrations', function (Blueprint $table): void {
            $table->integer('supplementary_exam_registration_id')->primary(); $table->integer('supplementary_exam_offering_id'); $table->integer('student_id'); $table->integer('student_course_registration_id'); $table->string('status'); $table->integer('current_slot')->nullable(); $table->string('eligibility_reason')->nullable(); $table->string('registration_channel')->nullable(); $table->dateTime('registered_at')->nullable(); $table->timestamps();
        });
        Schema::create('supplementary_exam_grade_results', function (Blueprint $table): void { $table->integer('supplementary_exam_grade_result_id')->primary(); $table->integer('supplementary_exam_registration_id'); $table->integer('supplementary_exam_offering_id'); $table->decimal('theoretical_mark')->nullable(); $table->string('status')->nullable(); $table->integer('submission_version')->nullable(); $table->timestamps(); });
        Schema::create('supplementary_exam_grade_submissions', function (Blueprint $table): void { $table->integer('supplementary_exam_grade_submission_id')->primary(); $table->integer('supplementary_exam_offering_id'); $table->integer('submission_version'); $table->string('status'); $table->dateTime('submitted_at')->nullable(); $table->dateTime('reviewed_at')->nullable(); $table->dateTime('published_at')->nullable(); });
        Schema::create('supplementary_exam_materializations', function (Blueprint $table): void { $table->integer('supplementary_exam_materialization_id')->primary(); $table->integer('supplementary_exam_registration_id'); $table->integer('supplementary_exam_offering_id'); });
        Schema::create('supplementary_exam_grader_assignments', function (Blueprint $table): void { $table->integer('supplementary_exam_grader_assignment_id')->primary(); $table->integer('supplementary_exam_offering_id'); $table->integer('faculty_member_id'); $table->integer('current_slot')->nullable(); $table->dateTime('assigned_at')->nullable(); $table->timestamps(); });
        Schema::create('faculty_members', function (Blueprint $table): void { $table->integer('faculty_member_id')->primary(); $table->integer('employee_id')->nullable(); $table->timestamps(); });
        Schema::create('employees', function (Blueprint $table): void { $table->integer('employee_id')->primary(); $table->string('employee_number')->nullable(); $table->string('first_name')->nullable(); $table->string('last_name')->nullable(); $table->timestamps(); });
    }
}
