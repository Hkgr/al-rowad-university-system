<?php

namespace Tests\Feature;

use App\Exceptions\CourseOfferingContextException;
use App\Exceptions\OfferingInstructorCoverageException;
use App\Exceptions\SemesterOfferingGovernanceException;
use App\Models\CourseOffering;
use App\Models\CourseOfferingExceptionRequest;
use App\Models\CourseOfferingExceptionReview;
use App\Models\ProgramCourse;
use App\Models\Semester;
use App\Models\SemesterOfferingRequest;
use App\Models\User;
use App\Services\CourseOfferingContextService;
use App\Services\CourseOfferingExceptionInvalidationService;
use App\Services\CourseOfferingInstructorCoverageService;
use App\Services\CourseOfferingOpeningService;
use App\Services\DataScopeService;
use App\Services\SemesterOfferingGovernanceService;
use App\Services\SemesterOfferingNormalOpenGate;
use App\Support\SemesterOfferingGovernance;
use App\Support\ExceptionalOpeningWorkflow;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class SemesterOfferingGovernancePhase1BehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::connection()->getPdo();
        $this->schema();
        $this->seedBase();
    }

    #[Test]
    public function regular_and_summer_minimum_rules_are_enforced_by_the_domain_service(): void
    {
        $service = $this->service();
        $validate = new ReflectionMethod($service, 'validateProposal');

        $mandatory = new ProgramCourse(['course_type' => 'mandatory']);
        $elective = new ProgramCourse(['course_type' => 'elective']);

        $validate->invoke($service, $this->offeringForSemester('first'), $mandatory, true, null);
        $this->assertDomainCode(
            fn () => $validate->invoke($service, $this->offeringForSemester('first'), $mandatory, true, 5),
            'semester_offering_minimum_enrollment_not_allowed',
        );
        $this->assertDomainCode(
            fn () => $validate->invoke($service, $this->offeringForSemester('second'), $elective, true, null),
            'semester_offering_minimum_enrollment_required',
        );
        $validate->invoke($service, $this->offeringForSemester('second'), $elective, true, 5);

        foreach ([$mandatory, $elective] as $course) {
            $this->assertDomainCode(
                fn () => $validate->invoke($service, $this->offeringForSemester('summer'), $course, true, null),
                'semester_offering_minimum_enrollment_required',
            );
            $validate->invoke($service, $this->offeringForSemester('summer'), $course, true, 5);
        }
    }

    #[Test]
    public function rejected_regular_mandatory_minimum_is_never_persisted(): void
    {
        $request = $this->draftRequest();
        $this->assertDomainCode(
            fn () => $this->service()->updateProposal($this->actor(dean: true), $request->courseOffering, ['minimum_enrollment' => 8]),
            'semester_offering_minimum_enrollment_not_allowed',
        );

        self::assertNull($request->fresh()->minimum_enrollment);
    }

    #[Test]
    public function incomplete_effective_coverage_blocks_submission_without_state_changes(): void
    {
        $request = $this->draftRequest();
        $coverage = Mockery::mock(CourseOfferingInstructorCoverageService::class);
        $coverage->shouldReceive('assertCompleteForNormalOpening')->once()->andThrow(
            OfferingInstructorCoverageException::incomplete(['missing_roles' => ['theoretical']]),
        );

        try {
            $this->service($coverage)->submit($this->actor(dean: true), $request->courseOffering);
            self::fail('Incomplete coverage must reject submission.');
        } catch (OfferingInstructorCoverageException) {
            self::assertSame(SemesterOfferingGovernance::STATUS_DRAFT, $request->fresh()->status);
            self::assertSame(0, (int) $request->fresh()->submission_version);
            self::assertSame(0, DB::table('semester_offering_reviews')->count());
        }
    }

    #[Test]
    public function submitted_request_is_immutable_but_return_edit_resubmit_preserves_history(): void
    {
        $request = $this->submittedRequest();
        $this->assertDomainCode(
            fn () => $this->service()->updateProposal($this->actor(dean: true), $request->courseOffering, ['is_selected' => true]),
            'semester_offering_state_conflict',
        );

        $service = $this->service($this->passingCoverage());
        $returned = $service->returnForEditing($this->actor(scientific: true), $request, 'استكمال البيانات');
        self::assertSame(SemesterOfferingGovernance::STATUS_RETURNED, $returned->status);

        $edited = $service->updateProposal($this->actor(dean: true), $returned->courseOffering, ['is_selected' => true]);
        $resubmitted = $service->submit($this->actor(dean: true), $edited->courseOffering);
        self::assertSame(2, (int) $resubmitted->submission_version);
        self::assertSame(['returned', 'pending'], DB::table('semester_offering_reviews')->orderBy('submission_version')->pluck('status')->all());
        self::assertSame(2, DB::table('semester_offering_reviews')->count());
        self::assertSame(1, DB::table('semester_offering_events')->where('event_type', 'resubmitted')->count());
    }

    #[Test]
    public function scientific_decision_requires_actual_role_permission_and_university_scope(): void
    {
        $request = $this->submittedRequest();
        foreach ([
            $this->actor(administrative: true),
            $this->actor(scientific: true, reviewPermission: false),
            $this->actor(scientific: true, universityScope: false),
        ] as $actor) {
            $this->assertDomainCode(
                fn () => $this->service()->approve($actor, $request),
                'semester_offering_forbidden',
            );
        }
    }

    #[Test]
    public function approval_atomically_opens_and_materializes_and_opening_failure_rolls_back(): void
    {
        $request = $this->submittedRequest();
        $approved = $this->service($this->passingCoverage(), actualOpening: true)
            ->approve($this->actor(scientific: true), $request);

        self::assertSame('open', $approved->courseOffering->fresh()->status);
        self::assertSame('approved', $approved->fresh()->status);
        self::assertNotNull($approved->fresh()->materialized_at);
        self::assertSame('approved', DB::table('semester_offering_reviews')->value('status'));
        self::assertSame(1, DB::table('semester_offering_events')->where('event_type', 'materialized')->count());

        $this->resetWorkflowRows();
        $request = $this->submittedRequest();
        $coverage = Mockery::mock(CourseOfferingInstructorCoverageService::class);
        $coverage->shouldReceive('assertCompleteForNormalOpening')->once()->andThrow(
            OfferingInstructorCoverageException::incomplete(['missing_roles' => ['theoretical']]),
        );
        try {
            $this->service($coverage, actualOpening: true)->approve($this->actor(scientific: true), $request);
            self::fail('Opening failure must roll back approval and materialization.');
        } catch (OfferingInstructorCoverageException) {
            self::assertSame('closed', $request->courseOffering->fresh()->status);
            self::assertSame('submitted', $request->fresh()->status);
            self::assertNull($request->fresh()->materialized_at);
            self::assertSame('pending', DB::table('semester_offering_reviews')->value('status'));
        }
    }

    #[Test]
    public function consumed_approval_and_generic_open_without_proof_cannot_reopen_after_closure(): void
    {
        $request = $this->submittedRequest();
        $service = $this->service($this->passingCoverage(), actualOpening: true);
        $approved = $service->approve($this->actor(scientific: true), $request);
        DB::table('course_offerings')->where('course_offering_id', 1)->update(['status' => 'closed']);

        $opening = $this->opening($this->passingCoverage());
        $this->assertDomainCode(
            fn () => $opening->normalOpen($approved->courseOffering->fresh(), $this->actor(dean: true)),
            'semester_offering_scientific_approval_required',
        );
        self::assertSame('closed', $approved->courseOffering->fresh()->status);
    }

    #[Test]
    public function stale_submitted_course_type_blocks_scientific_approval(): void
    {
        $request = $this->submittedRequest();
        DB::table('program_courses')->where('program_course_id', 1)->update(['course_type' => 'elective']);

        $this->assertDomainCode(
            fn () => $this->service()->approve($this->actor(scientific: true), $request),
            'semester_offering_proposal_stale',
        );
        self::assertSame('submitted', $request->fresh()->status);
        self::assertSame('pending', DB::table('semester_offering_reviews')->value('status'));
    }

    #[Test]
    public function governance_history_and_open_program_legacy_rows_lock_generic_identity_changes(): void
    {
        $context = new CourseOfferingContextService(Mockery::mock(DataScopeService::class));
        $request = $this->draftRequest();
        self::assertTrue($context->identityWouldChange($request->courseOffering, 2, 1, 1, 1));
        self::assertTrue($context->hasHistoricalDependents($request->courseOffering));
        try {
            $context->assertIdentityChangeAllowed($request->courseOffering, 2, 1, 1, 1);
            self::fail('Governed offering identity mutation must fail.');
        } catch (CourseOfferingContextException $exception) {
            self::assertSame(CourseOfferingContextException::OFFERING_IDENTITY_LOCKED, $exception->errorCode);
        }

        DB::table('semester_offering_requests')->delete();
        DB::table('course_offerings')->where('course_offering_id', 1)->update(['status' => 'open']);
        $legacyOpen = CourseOffering::query()->findOrFail(1);
        self::assertTrue($context->hasHistoricalDependents($legacyOpen));
        try {
            $context->assertIdentityChangeAllowed($legacyOpen, 2, 1, 1, 1);
            self::fail('OPEN program offering must not be repurposed.');
        } catch (CourseOfferingContextException $exception) {
            self::assertSame(CourseOfferingContextException::OFFERING_IDENTITY_LOCKED, $exception->errorCode);
        }
    }

    #[Test]
    public function exceptional_opening_remains_a_separate_approved_dual_vp_path(): void
    {
        DB::table('course_offering_exception_requests')->insert([
            'course_offering_exception_request_id' => 1, 'course_offering_id' => 1,
            'requested_by_user_id' => 1, 'reason' => 'استثناء معتمد', 'status' => 'submitted',
            'submission_version' => 1, 'current_slot' => 1, 'snapshot_course_id' => 1,
            'snapshot_academic_program_id' => 1, 'snapshot_academic_year_id' => 1,
            'snapshot_semester_id' => 1,
        ]);
        foreach ([ExceptionalOpeningWorkflow::AUTHORITY_SCIENTIFIC, ExceptionalOpeningWorkflow::AUTHORITY_ADMINISTRATIVE] as $index => $authority) {
            DB::table('course_offering_exception_reviews')->insert([
                'course_offering_exception_review_id' => $index + 1,
                'course_offering_exception_request_id' => 1, 'submission_version' => 1,
                'review_authority' => $authority, 'status' => ExceptionalOpeningWorkflow::REVIEW_APPROVED,
                'reviewed_by_user_id' => 2, 'reviewed_at' => now(),
            ]);
        }

        $coverage = Mockery::mock(CourseOfferingInstructorCoverageService::class);
        $coverage->shouldNotReceive('assertCompleteForNormalOpening');
        DB::transaction(function () use ($coverage): void {
            $this->opening($coverage)->openFromApprovedException(
                CourseOffering::query()->findOrFail(1),
                CourseOfferingExceptionRequest::query()->findOrFail(1),
                CourseOfferingExceptionReview::query()->findOrFail(1),
                CourseOfferingExceptionReview::query()->findOrFail(2),
            );
        });

        self::assertSame('open', CourseOffering::query()->findOrFail(1)->status);
        self::assertNotNull(CourseOfferingExceptionRequest::query()->findOrFail(1)->materialized_at);
    }

    private function service(?CourseOfferingInstructorCoverageService $coverage = null, bool $actualOpening = false): SemesterOfferingGovernanceService
    {
        $scope = Mockery::mock(DataScopeService::class);
        $scope->shouldReceive('canAccessProgram')->andReturn(true);
        $scope->shouldReceive('hasActualUniversityScope')->andReturnUsing(fn (User $user): bool => (bool) ($user->test_university_scope ?? false));
        $coverage ??= $this->passingCoverage();
        $opening = $actualOpening ? $this->opening($coverage) : Mockery::mock(CourseOfferingOpeningService::class);

        return new SemesterOfferingGovernanceService($scope, $coverage, $opening);
    }

    private function opening(CourseOfferingInstructorCoverageService $coverage): CourseOfferingOpeningService
    {
        $invalidation = Mockery::mock(CourseOfferingExceptionInvalidationService::class);
        $invalidation->shouldReceive('supersedeCurrentForNormalOpen')->zeroOrMoreTimes();

        return new CourseOfferingOpeningService($coverage, $invalidation, new SemesterOfferingNormalOpenGate());
    }

    private function passingCoverage(): CourseOfferingInstructorCoverageService
    {
        $coverage = Mockery::mock(CourseOfferingInstructorCoverageService::class);
        $coverage->shouldReceive('assertCompleteForNormalOpening')->zeroOrMoreTimes();

        return $coverage;
    }

    private function actor(bool $dean = false, bool $scientific = false, bool $administrative = false, bool $reviewPermission = true, bool $universityScope = true): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['user_id' => $scientific ? 2 : 1, 'test_university_scope' => $universityScope]);
        $user->shouldReceive('isDean')->andReturn($dean);
        $user->shouldReceive('isScientificVicePresident')->andReturn($scientific);
        $user->shouldReceive('hasRoleCode')->with('vice_president_administrative')->andReturn($administrative);
        $permissions = [];
        if ($dean) {
            $permissions[] = SemesterOfferingGovernance::PERMISSION_MANAGE;
            $permissions[] = SemesterOfferingGovernance::PERMISSION_VIEW;
        }
        if ($scientific && $reviewPermission) {
            $permissions[] = SemesterOfferingGovernance::PERMISSION_REVIEW_SCIENTIFIC;
            $permissions[] = SemesterOfferingGovernance::PERMISSION_VIEW;
        }
        $user->shouldReceive('effectivePermissions')->andReturn(collect($permissions));

        return $user;
    }

    private function draftRequest(): SemesterOfferingRequest
    {
        DB::table('semester_offering_requests')->insert([
            'semester_offering_request_id' => 1, 'course_offering_id' => 1, 'program_course_id' => 1,
            'course_type' => 'mandatory', 'is_selected' => 1, 'minimum_enrollment' => null,
            'status' => 'draft', 'submission_version' => 0, 'created_by_user_id' => 1,
        ]);

        return SemesterOfferingRequest::query()->with('courseOffering')->findOrFail(1);
    }

    private function submittedRequest(): SemesterOfferingRequest
    {
        DB::table('semester_offering_requests')->insert([
            'semester_offering_request_id' => 1, 'course_offering_id' => 1, 'program_course_id' => 1,
            'course_type' => 'mandatory', 'is_selected' => 1, 'minimum_enrollment' => null,
            'status' => 'submitted', 'submission_version' => 1, 'created_by_user_id' => 1,
            'submitted_by_user_id' => 1, 'submitted_at' => now(),
        ]);
        DB::table('semester_offering_reviews')->insert([
            'semester_offering_review_id' => 1, 'semester_offering_request_id' => 1,
            'submission_version' => 1, 'status' => 'pending',
        ]);

        return SemesterOfferingRequest::query()->with('courseOffering')->findOrFail(1);
    }

    private function offeringForSemester(string $code): CourseOffering
    {
        $offering = new CourseOffering(['status' => 'closed']);
        $offering->setRelation('semester', new Semester(['semester_code' => $code]));

        return $offering;
    }

    private function assertDomainCode(callable $action, string $code): void
    {
        try {
            $action();
            self::fail('Expected domain exception '.$code);
        } catch (SemesterOfferingGovernanceException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }

    private function resetWorkflowRows(): void
    {
        DB::table('semester_offering_events')->delete();
        DB::table('semester_offering_reviews')->delete();
        DB::table('semester_offering_requests')->delete();
        DB::table('course_offerings')->where('course_offering_id', 1)->update(['status' => 'closed']);
    }

    private function seedBase(): void
    {
        DB::table('users')->insert([['user_id' => 1, 'username' => 'dean'], ['user_id' => 2, 'username' => 'svp']]);
        DB::table('colleges')->insert(['college_id' => 1]);
        DB::table('departments')->insert(['department_id' => 1, 'college_id' => 1]);
        DB::table('academic_programs')->insert(['academic_program_id' => 1, 'department_id' => 1]);
        DB::table('academic_years')->insert(['academic_year_id' => 1]);
        DB::table('semesters')->insert([['semester_id' => 1, 'semester_code' => 'first'], ['semester_id' => 2, 'semester_code' => 'second'], ['semester_id' => 3, 'semester_code' => 'summer']]);
        DB::table('courses')->insert([['course_id' => 1, 'theoretical_hours' => 2, 'practical_hours' => 0], ['course_id' => 2, 'theoretical_hours' => 2, 'practical_hours' => 0]]);
        DB::table('program_courses')->insert(['program_course_id' => 1, 'academic_program_id' => 1, 'course_id' => 1, 'course_type' => 'mandatory', 'is_active' => 1]);
        DB::table('course_offerings')->insert(['course_offering_id' => 1, 'course_id' => 1, 'academic_program_id' => 1, 'academic_year_id' => 1, 'semester_id' => 1, 'status' => 'closed']);
    }

    private function schema(): void
    {
        Schema::create('users', fn (Blueprint $t) => $t->increments('user_id')->string('username')->nullable());
        Schema::create('colleges', fn (Blueprint $t) => $t->increments('college_id'));
        Schema::create('departments', fn (Blueprint $t) => $t->increments('department_id')->unsignedInteger('college_id')->nullable());
        Schema::create('academic_programs', fn (Blueprint $t) => $t->increments('academic_program_id')->unsignedInteger('department_id')->nullable());
        Schema::create('academic_years', fn (Blueprint $t) => $t->increments('academic_year_id'));
        Schema::create('semesters', fn (Blueprint $t) => $t->increments('semester_id')->string('semester_code'));
        Schema::create('courses', function (Blueprint $t): void { $t->increments('course_id'); $t->integer('theoretical_hours')->default(0); $t->integer('practical_hours')->default(0); });
        Schema::create('program_courses', function (Blueprint $t): void { $t->increments('program_course_id'); $t->integer('academic_program_id'); $t->integer('course_id'); $t->string('course_type'); $t->boolean('is_active'); });
        Schema::create('course_offerings', function (Blueprint $t): void { $t->increments('course_offering_id'); $t->integer('course_id'); $t->integer('academic_program_id')->nullable(); $t->integer('academic_year_id'); $t->integer('semester_id'); $t->integer('faculty_member_id')->nullable(); $t->integer('capacity')->default(40); $t->integer('available_seats')->default(40); $t->string('status'); $t->timestamps(); });
        Schema::create('course_offering_instructors', function (Blueprint $t): void { $t->increments('course_offering_instructor_id'); $t->integer('course_offering_id'); $t->integer('faculty_member_id')->nullable(); $t->string('instructor_role'); $t->boolean('is_active')->default(true); });
        Schema::create('teaching_assignment_requests', function (Blueprint $t): void { $t->increments('teaching_assignment_request_id'); $t->integer('course_offering_id'); $t->integer('current_slot')->nullable(); $t->string('action_type')->nullable(); $t->string('status')->nullable(); });
        Schema::create('course_offering_exception_requests', function (Blueprint $t): void { $t->increments('course_offering_exception_request_id'); $t->integer('course_offering_id'); $t->integer('requested_by_user_id'); $t->text('reason'); $t->string('status'); $t->integer('submission_version'); $t->integer('current_slot')->nullable(); $t->integer('snapshot_course_id'); $t->integer('snapshot_academic_program_id')->nullable(); $t->integer('snapshot_academic_year_id'); $t->integer('snapshot_semester_id'); $t->integer('snapshot_department_id')->nullable(); $t->dateTime('approved_at')->nullable(); $t->dateTime('materialized_at')->nullable(); $t->timestamps(); });
        Schema::create('course_offering_exception_reviews', function (Blueprint $t): void { $t->increments('course_offering_exception_review_id'); $t->integer('course_offering_exception_request_id'); $t->integer('submission_version'); $t->string('review_authority'); $t->string('status'); $t->integer('reviewed_by_user_id')->nullable(); $t->dateTime('reviewed_at')->nullable(); $t->text('notes')->nullable(); $t->timestamps(); });
        Schema::create('semester_offering_requests', function (Blueprint $t): void { $t->increments('semester_offering_request_id'); $t->integer('course_offering_id'); $t->integer('program_course_id'); $t->string('course_type'); $t->boolean('is_selected'); $t->integer('minimum_enrollment')->nullable(); $t->string('status'); $t->integer('submission_version')->default(0); $t->integer('created_by_user_id'); $t->integer('submitted_by_user_id')->nullable(); $t->dateTime('submitted_at')->nullable(); $t->dateTime('approved_at')->nullable(); $t->dateTime('materialized_at')->nullable(); $t->timestamps(); });
        Schema::create('semester_offering_reviews', function (Blueprint $t): void { $t->increments('semester_offering_review_id'); $t->integer('semester_offering_request_id'); $t->integer('submission_version'); $t->string('status'); $t->integer('reviewed_by_user_id')->nullable(); $t->dateTime('reviewed_at')->nullable(); $t->text('reason')->nullable(); $t->timestamps(); });
        Schema::create('semester_offering_events', function (Blueprint $t): void { $t->increments('semester_offering_event_id'); $t->integer('semester_offering_request_id'); $t->integer('submission_version'); $t->string('event_type'); $t->integer('actor_user_id'); $t->text('note')->nullable(); $t->dateTime('occurred_at'); });
    }
}
