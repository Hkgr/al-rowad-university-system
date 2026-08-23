<?php

namespace Tests\Feature;

use App\Exceptions\GradeException;
use App\Models\SupplementaryExamGradeSubmission;
use App\Models\SupplementaryExamOffering;
use App\Models\SupplementaryExamPeriod;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\GradeService;
use App\Services\SupplementaryExamGradingService;
use App\Support\SupplementaryExamGradingGovernance;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * Runs the complete booted Phase-4 schema and workflow fixture as a dedicated
 * behavior suite, plus one Phase-5 public-service journey. Source-contract
 * checks live elsewhere.
 */
class SupplementaryExamRegistrationBehaviorTest extends SupplementaryExamEligibilitySchemaReadyRuntimeTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createGradingJourneySchema();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ([
            'supplementary_exam_grade_events',
            'supplementary_exam_grade_submissions',
            'supplementary_exam_grade_results',
            'supplementary_exam_grader_assignments',
            'faculty_members',
            'employees',
            'account_statuses',
            'departments',
            'user_roles',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();

        parent::tearDown();
    }

    #[Test]
    public function grading_journey_uses_public_services_from_assignment_through_publication(): void
    {
        [$examOfficer, $professor] = $this->seedGradingJourney();
        $this->assertTrue(SupplementaryExamGradingGovernance::schemaReady());
        $service = $this->gradingService();
        $offering = SupplementaryExamOffering::query()->findOrFail(600);
        $period = SupplementaryExamPeriod::query()->findOrFail(500);

        $assignment = $service->assign($examOfficer, $offering, 901);
        $opened = $service->openGrading($examOfficer, $period);
        $draft = $service->saveDrafts($professor, $offering, [[
            'supplementary_exam_registration_id' => 700,
            'theoretical_mark' => 40,
        ]]);
        $submitted = $service->submit($professor, $offering);
        $submissionId = (int) SupplementaryExamGradeSubmission::query()->value(
            'supplementary_exam_grade_submission_id',
        );
        $approved = $service->review($examOfficer, $submissionId, 'approve');
        $published = $service->review($examOfficer, $submissionId, 'publish');

        $this->assertSame(901, (int) $assignment->faculty_member_id);
        $this->assertSame('grading_open', $opened->status);
        $this->assertSame('draft', $draft['workflow_status']);
        $this->assertSame('submitted', $submitted['workflow_status']);
        $this->assertSame('approved', $approved['workflow_status']);
        $this->assertSame('published', $published['workflow_status']);
        $this->assertSame('results_published', SupplementaryExamPeriod::query()->findOrFail(500)->status);
        $this->assertDatabaseHas('supplementary_exam_grade_submissions', [
            'supplementary_exam_grade_submission_id' => $submissionId,
            'status' => 'published',
            'submitted_by_user_id' => 901,
            'reviewed_by_user_id' => 900,
            'published_by_user_id' => 900,
        ]);
        $this->assertDatabaseHas('supplementary_exam_grade_results', [
            'supplementary_exam_registration_id' => 700,
            'student_course_registration_id' => 300,
            'theoretical_mark' => 40,
            'status' => 'published',
            'submission_version' => 1,
        ]);
        foreach (['draft_saved', 'submitted', 'approved', 'published'] as $eventType) {
            $this->assertDatabaseHas('supplementary_exam_grade_events', [
                'event_type' => $eventType,
            ]);
        }
        foreach (['grading_opened', 'grading_submitted', 'grading_approved', 'grading_published'] as $eventType) {
            $this->assertDatabaseHas('supplementary_exam_period_events', [
                'supplementary_exam_period_id' => 500,
                'event_type' => $eventType,
            ]);
        }
    }

    #[Test]
    public function public_grading_boundaries_reject_program_and_university_scope_gaps_atomically(): void
    {
        [$examOfficer] = $this->seedGradingJourney();
        $offering = SupplementaryExamOffering::query()->findOrFail(600);
        $period = SupplementaryExamPeriod::query()->findOrFail(500);

        try {
            $this->gradingService(programScope: false)->assign($examOfficer, $offering, 901);
            $this->fail('Expected program-scope assignment rejection.');
        } catch (GradeException $exception) {
            $this->assertSame('supplementary_grade_out_of_scope', $exception->errorCode);
            $this->assertSame(403, $exception->status);
        }
        try {
            $this->gradingService(universityScope: false)->openGrading($examOfficer, $period);
            $this->fail('Expected university-scope grading-open rejection.');
        } catch (GradeException $exception) {
            $this->assertSame('supplementary_grading_out_of_scope', $exception->errorCode);
            $this->assertSame(403, $exception->status);
        }

        $this->assertDatabaseCount('supplementary_exam_grader_assignments', 0);
        $this->assertDatabaseHas('supplementary_exam_periods', [
            'supplementary_exam_period_id' => 500,
            'status' => 'registration_closed',
        ]);
        $this->assertDatabaseMissing('supplementary_exam_period_events', [
            'supplementary_exam_period_id' => 500,
            'event_type' => 'grading_opened',
        ]);
    }

    private function gradingService(
        bool $programScope = true,
        bool $universityScope = true,
        bool $facultyScope = true,
    ): SupplementaryExamGradingService
    {
        $grades = $this->createMock(GradeService::class);
        $grades->method('gradingPolicyLimits')->willReturn([
            'theoretical_max_mark' => 60.0,
            'practical_max_mark' => 40.0,
            'minimum_theoretical_mark' => 30.0,
            'minimum_practical_mark' => 20.0,
            'minimum_final_mark' => 50.0,
        ]);
        $grades->method('buildCalculationForRequiredParts')->willReturn([
            'final_mark' => 70.0,
            'result_status_code' => 'passed',
            'letter_grade' => 'C+',
            'grade_points' => 2.5,
            'calculation_details' => [],
        ]);
        $scope = $this->createMock(DataScopeService::class);
        $scope->method('hasActualUniversityScope')->willReturn($universityScope);
        $scope->method('canMutateProgram')->willReturn($programScope);
        $scope->method('canMutateFacultyMember')->willReturn($facultyScope);
        $scope->method('scopes')->willReturn([['type' => 'university', 'id' => 1]]);

        return new SupplementaryExamGradingService($grades, $scope);
    }

    /** @return array{User, User} */
    private function seedGradingJourney(): array
    {
        $moduleId = (int) DB::table('system_modules')->value('module_id');
        $examRoleId = (int) DB::table('roles')->where('role_code', 'exam_officer')->value('role_id');
        $professorRoleId = DB::table('roles')->insertGetId([
            'role_code' => 'doctor_instructor',
            'is_active' => 1,
        ]);
        $permissionIds = DB::table('permissions')
            ->whereIn('permission_code', [
                SupplementaryExamGradingGovernance::VIEW,
                SupplementaryExamGradingGovernance::ASSIGN,
                SupplementaryExamGradingGovernance::ENTER,
                SupplementaryExamGradingGovernance::REVIEW,
                SupplementaryExamGradingGovernance::PUBLISH,
            ])
            ->pluck('permission_id', 'permission_code');
        foreach ([
            SupplementaryExamGradingGovernance::VIEW,
            SupplementaryExamGradingGovernance::ASSIGN,
            SupplementaryExamGradingGovernance::REVIEW,
            SupplementaryExamGradingGovernance::PUBLISH,
        ] as $permissionCode) {
            DB::table('role_permissions')->insert([
                'role_id' => $examRoleId,
                'permission_id' => $permissionIds->get($permissionCode),
            ]);
        }
        foreach ([SupplementaryExamGradingGovernance::VIEW, SupplementaryExamGradingGovernance::ENTER] as $permissionCode) {
            DB::table('role_permissions')->insert([
                'role_id' => $professorRoleId,
                'permission_id' => $permissionIds->get($permissionCode),
            ]);
        }

        DB::table('account_statuses')->insert(['account_status_id' => 1, 'status_code' => 'active']);
        DB::table('employees')->insert([
            'employee_id' => 901,
            'employee_number' => 'P-901',
            'first_name' => 'Journey',
            'last_name' => 'Professor',
        ]);
        DB::table('faculty_members')->insert([
            'faculty_member_id' => 901,
            'employee_id' => 901,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            ['user_id' => 900, 'employee_id' => null, 'account_status_id' => null],
            ['user_id' => 901, 'employee_id' => 901, 'account_status_id' => 1],
        ]);
        DB::table('user_roles')->insert([
            ['user_id' => 900, 'role_id' => $examRoleId, 'assigned_at' => now(), 'is_active' => 1],
            ['user_id' => 901, 'role_id' => $professorRoleId, 'assigned_at' => now(), 'is_active' => 1],
        ]);

        DB::table('academic_programs')->insert(['academic_program_id' => 10]);
        DB::table('courses')->insert(['course_id' => 20]);
        DB::table('course_offerings')->insert([
            'course_offering_id' => 100,
            'course_id' => 20,
            'academic_program_id' => 10,
        ]);
        DB::table('semesters')->insert(['semester_id' => 1, 'semester_order' => 3]);
        DB::table('supplementary_exam_periods')->insert([
            'supplementary_exam_period_id' => 500,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'status' => 'registration_closed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('supplementary_exam_offerings')->insert([
            'supplementary_exam_offering_id' => 600,
            'supplementary_exam_period_id' => 500,
            'academic_program_id' => 10,
            'course_id' => 20,
            'status' => 'open',
            'opened_by_user_id' => 900,
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('supplementary_exam_offering_sources')->insert([
            'supplementary_exam_offering_id' => 600,
            'course_offering_id' => 100,
            'created_at' => now(),
        ]);
        DB::table('students')->insert([
            'student_id' => 200,
            'academic_program_id' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('registration_statuses')->insert([
            'registration_status_id' => 1,
            'status_code' => 'registered',
        ]);
        DB::table('result_statuses')->insert([
            ['result_status_id' => 1, 'status_code' => 'failed', 'is_active' => 1],
            ['result_status_id' => 2, 'status_code' => 'passed', 'is_active' => 1],
        ]);
        DB::table('student_course_registrations')->insert([
            'student_course_registration_id' => 300,
            'student_id' => 200,
            'course_offering_id' => 100,
            'registration_status_id' => 1,
            'result_status_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('student_course_results')->insert([
            'student_course_result_id' => 400,
            'student_course_registration_id' => 300,
            'result_status_id' => 1,
            'is_deprived' => 0,
            'practical_total' => 30,
        ]);
        DB::table('grade_components')->insert([
            ['course_offering_id' => 100, 'component_type' => 'theoretical', 'is_required' => 1, 'max_mark' => 60],
            ['course_offering_id' => 100, 'component_type' => 'practical', 'is_required' => 1, 'max_mark' => 40],
        ]);
        DB::table('approval_statuses')->insert([
            'approval_status_id' => 1,
            'status_code' => 'approved',
            'is_active' => 1,
        ]);
        DB::table('grade_approvals')->insert([
            'grade_approval_id' => 1,
            'course_offering_id' => 100,
            'approval_status_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('supplementary_exam_registrations')->insert([
            'supplementary_exam_registration_id' => 700,
            'supplementary_exam_offering_id' => 600,
            'student_id' => 200,
            'student_course_registration_id' => 300,
            'status' => 'registered',
            'current_slot' => 1,
            'eligibility_reason' => 'failed_theoretical',
            'registration_channel' => 'registration_office',
            'registered_by_user_id' => 900,
            'registered_at' => now(),
            'eligibility_checked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(5, DB::table('permissions')
            ->whereIn('permission_code', [
                SupplementaryExamGradingGovernance::VIEW,
                SupplementaryExamGradingGovernance::ASSIGN,
                SupplementaryExamGradingGovernance::ENTER,
                SupplementaryExamGradingGovernance::REVIEW,
                SupplementaryExamGradingGovernance::PUBLISH,
            ])->where('module_id', $moduleId)->count());

        return [User::query()->findOrFail(900), User::query()->findOrFail(901)];
    }

    private function createGradingJourneySchema(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->integer('employee_id')->nullable();
            $table->integer('account_status_id')->nullable();
        });
        Schema::table('academic_programs', fn (Blueprint $table) => $table->integer('department_id')->nullable());
        Schema::table('course_offerings', function (Blueprint $table): void {
            $table->integer('course_id')->nullable();
            $table->integer('academic_program_id')->nullable();
        });
        Schema::table('result_statuses', fn (Blueprint $table) => $table->boolean('is_active')->default(true));
        Schema::table('student_course_results', function (Blueprint $table): void {
            $table->boolean('is_deprived')->default(false);
            $table->decimal('practical_total', 5, 2)->nullable();
        });
        Schema::table('grade_components', fn (Blueprint $table) => $table->decimal('max_mark', 5, 2)->default(0));
        Schema::table('supplementary_exam_materializations', function (Blueprint $table): void {
            $table->integer('supplementary_exam_registration_id')->nullable();
            $table->integer('supplementary_exam_offering_id')->nullable();
            $table->integer('supplementary_exam_grade_result_id')->nullable();
        });

        Schema::create('departments', function (Blueprint $table): void {
            $table->increments('department_id');
            $table->integer('college_id')->nullable();
        });
        Schema::create('user_roles', function (Blueprint $table): void {
            $table->increments('user_role_id');
            $table->integer('user_id');
            $table->integer('role_id');
            $table->integer('assigned_by_user_id')->nullable();
            $table->dateTime('assigned_at');
            $table->boolean('is_active');
        });
        Schema::create('account_statuses', function (Blueprint $table): void {
            $table->increments('account_status_id');
            $table->string('status_code');
        });
        Schema::create('employees', function (Blueprint $table): void {
            $table->increments('employee_id');
            $table->string('employee_number')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
        });
        Schema::create('faculty_members', function (Blueprint $table): void {
            $table->increments('faculty_member_id');
            $table->integer('employee_id');
            $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::create('supplementary_exam_grader_assignments', function (Blueprint $table): void {
            $table->increments('supplementary_exam_grader_assignment_id');
            $table->integer('supplementary_exam_offering_id');
            $table->integer('faculty_member_id');
            $table->tinyInteger('current_slot')->nullable();
            $table->integer('assigned_by_user_id');
            $table->dateTime('assigned_at');
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();
        });
        Schema::create('supplementary_exam_grade_results', function (Blueprint $table): void {
            $table->increments('supplementary_exam_grade_result_id');
            $table->integer('supplementary_exam_registration_id');
            $table->integer('supplementary_exam_offering_id');
            $table->integer('student_course_registration_id');
            $table->integer('student_id');
            $table->decimal('theoretical_mark', 5, 2)->nullable();
            $table->string('status', 24);
            $table->integer('submission_version');
            $table->integer('last_edited_by_user_id')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('supplementary_exam_grade_submissions', function (Blueprint $table): void {
            $table->increments('supplementary_exam_grade_submission_id');
            $table->integer('supplementary_exam_offering_id');
            $table->integer('grader_assignment_id');
            $table->integer('submission_version');
            $table->string('status', 24);
            $table->integer('submitted_by_user_id')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->integer('reviewed_by_user_id')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->integer('published_by_user_id')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('supplementary_exam_grade_events', function (Blueprint $table): void {
            $table->increments('supplementary_exam_grade_event_id');
            $table->integer('supplementary_exam_grade_result_id');
            $table->integer('supplementary_exam_grade_submission_id')->nullable();
            $table->string('event_type', 40);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->integer('submission_version');
            $table->decimal('theoretical_mark', 5, 2)->nullable();
            $table->integer('actor_user_id');
            $table->text('notes')->nullable();
            $table->dateTime('created_at');
        });

        $moduleId = (int) DB::table('system_modules')->value('module_id');
        foreach ([
            SupplementaryExamGradingGovernance::VIEW,
            SupplementaryExamGradingGovernance::ASSIGN,
            SupplementaryExamGradingGovernance::ENTER,
            SupplementaryExamGradingGovernance::REVIEW,
            SupplementaryExamGradingGovernance::PUBLISH,
        ] as $permissionCode) {
            DB::table('permissions')->insert([
                'permission_code' => $permissionCode,
                'is_active' => 1,
                'module_id' => $moduleId,
            ]);
        }
    }
}
