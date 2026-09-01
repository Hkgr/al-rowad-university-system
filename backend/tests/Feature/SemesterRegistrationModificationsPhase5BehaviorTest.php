<?php

namespace Tests\Feature;

use App\Exceptions\RegistrationException;
use App\Http\Controllers\Api\StudentSelfRegistrationController;
use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Models\User;
use App\Services\AcademicCalendarPolicyService;
use App\Services\AcademicRequirementService;
use App\Services\AcademicTermResolver;
use App\Services\CourseOfferingScheduleService;
use App\Services\DataScopeService;
use App\Services\GradeService;
use App\Services\RegistrationModificationService;
use App\Services\RegistrationRequestService;
use App\Services\RegistrationService;
use App\Support\CourseRegistrationDeadlineResult;
use App\Support\CourseRegistrationPhase;
use App\Support\RegistrationProjectionContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class SemesterRegistrationModificationsPhase5BehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->schema();
        $this->seed();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_approved_initial_request_blocks_direct_self_drop_without_phase5_schema(): void
    {
        $this->approvedInitialRequest();

        $this->expectRegistrationCode(
            fn () => $this->registrationService()->selfDrop($this->student(), $this->registration()),
            RegistrationException::REGISTRATION_MODIFICATION_REQUIRED,
        );

        self::assertSame('registered', $this->currentStatus());
        self::assertFalse(Schema::hasTable('student_registration_modification_requests'));
    }

    public function test_student_http_drop_endpoint_preserves_a_workflow_managed_registration(): void
    {
        $this->approvedInitialRequest();
        $request = Request::create('/api/v1/student/registration/10/drop', 'POST');
        $user = new User(['user_id' => 7, 'student_id' => 1]);
        $user->setAttribute('user_id', 7);
        $user->setAttribute('student_id', 1);
        $request->setUserResolver(fn (): User => $user);
        $controller = new StudentSelfRegistrationController(
            $this->createMock(RegistrationRequestService::class),
            $this->createMock(RegistrationModificationService::class),
        );

        $this->expectRegistrationCode(
            fn () => $controller->drop($request, $this->registration(), $this->registrationService()),
            RegistrationException::REGISTRATION_MODIFICATION_REQUIRED,
            409,
        );

        self::assertSame('registered', $this->currentStatus());
    }

    public function test_term_governance_blocks_a_registration_not_linked_to_an_original_request_item(): void
    {
        $this->approvedInitialRequest(withItemLink: false);

        $this->expectRegistrationCode(
            fn () => $this->registrationService()->selfDrop($this->student(), $this->registration()),
            RegistrationException::REGISTRATION_MODIFICATION_REQUIRED,
        );

        self::assertSame('registered', $this->currentStatus());
    }

    public function test_legacy_registration_without_approved_request_keeps_existing_self_drop_behavior(): void
    {
        $updated = $this->registrationService()->selfDrop($this->student(), $this->registration());

        self::assertSame('dropped', $updated->registrationStatus?->status_code);
        self::assertSame('dropped', $this->currentStatus());
    }

    public function test_trusted_low_level_drop_transition_remains_available_for_atomic_phase5_approval(): void
    {
        DB::transaction(function (): void {
            $registration = StudentCourseRegistration::query()->whereKey(10)->lockForUpdate()->firstOrFail();
            $registration->load('registrationStatus');
            $offering = $registration->courseOffering()->lockForUpdate()->firstOrFail();

            $this->registrationService()->transitionRegisteredToDropped($registration, $offering);
        });

        self::assertSame('dropped', $this->currentStatus());
    }

    public function test_real_delta_removal_stays_provisional_until_atomic_advisor_approval(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-05T00:00:00Z'));
        $this->phase5Schema();
        $this->approvedInitialRequest();
        $phase = (object) ['value' => CourseRegistrationPhase::STUDENT_OPEN];
        $registration = Mockery::mock(RegistrationService::class);
        $registration->shouldReceive('lockStudent')->andReturnUsing(fn (): Student => $this->student());
        $registration->shouldReceive('assertCourseRegistrationStudentWindowOpen')->zeroOrMoreTimes();
        $registration->shouldReceive('courseRegistrationDeadlines')->zeroOrMoreTimes()
            ->andReturnUsing(fn (): CourseRegistrationDeadlineResult => $this->deadline($phase->value));
        $registration->shouldReceive('hoursSnapshot')->zeroOrMoreTimes()
            ->andReturnUsing(function (Student $student, int $yearId, int $semesterId, ?RegistrationProjectionContext $projection = null): array {
                $removed = $projection?->excludedRegistrationIds() ?? [];

                return [
                    'registered_hours' => in_array(10, $removed, true) ? 0 : 3,
                    'max_allowed_hours' => 18,
                    'official_cgpa' => 2.5,
                ];
            });
        $registration->shouldReceive('getSelfRegistrationOfferings')->zeroOrMoreTimes()->andReturn(collect());
        $registration->shouldReceive('transitionRegisteredToDropped')->once()
            ->andReturnUsing(function (StudentCourseRegistration $current): void {
                DB::table('student_course_registrations')->where('student_course_registration_id', $current->getKey())
                    ->update(['registration_status_id' => 2]);
            });

        $requirements = Mockery::mock(AcademicRequirementService::class);
        $requirements->shouldReceive('validateProjectedCandidates')->zeroOrMoreTimes()->andReturn([]);
        $schedules = Mockery::mock(CourseOfferingScheduleService::class);
        $schedules->shouldReceive('registrationEvaluations')->zeroOrMoreTimes()
            ->andReturnUsing(function (Student $student, $targets): array {
                return $targets->mapWithKeys(fn ($offering): array => [(int) $offering->getKey() => [
                    'reason' => null,
                    'schedule' => ['schema_ready' => true, 'components_defined' => true, 'complete' => true, 'slots' => []],
                    'conflicts' => [],
                ]])->all();
            });
        $terms = Mockery::mock(AcademicTermResolver::class);
        $terms->shouldReceive('uniqueCurrentAcademicYearId')->andReturn(1);
        $scope = Mockery::mock(DataScopeService::class);
        $scope->shouldReceive('canStaffAccessStudent')->zeroOrMoreTimes()->andReturnTrue();
        $service = new RegistrationModificationService($registration, $requirements, $schedules, $terms, $scope);
        $studentActor = User::query()->findOrFail(7);

        $draft = $service->createDraft($this->student(), $studentActor, 1);
        $item = \App\Models\StudentRegistrationModificationItem::query()->findOrFail(
            $draft['items'][0]['student_registration_modification_item_id'],
        );
        $service->toggleBaselineItem($this->student(), $studentActor, $item, 'remove');
        $submitted = $service->submit($this->student(), $studentActor, 1);

        self::assertSame('submitted', $submitted['status']);
        self::assertSame('registered', $this->currentStatus());
        self::assertSame('approved', DB::table('student_registration_requests')->where('student_registration_request_id', 20)->value('status'));
        self::assertSame(10, (int) DB::table('student_registration_request_items')->where('student_registration_request_item_id', 30)->value('student_course_registration_id'));

        $phase->value = CourseRegistrationPhase::ADVISOR_REVIEW;
        $advisor = Mockery::mock(User::class)->makePartial();
        $advisor->setRawAttributes(['user_id' => 8, 'username' => 'advisor']);
        $advisor->exists = true;
        $advisor->shouldReceive('hasPermission')->with('registration_requests.review')->andReturnTrue();
        $approved = $service->approve(
            $advisor,
            \App\Models\StudentRegistrationModificationRequest::query()->findOrFail($draft['student_registration_modification_request_id']),
        );

        self::assertSame('approved', $approved['status']);
        self::assertNull($approved['current_slot']);
        self::assertNotNull($approved['materialized_at']);
        self::assertSame('dropped', $this->currentStatus());
        self::assertSame('approved', DB::table('student_registration_requests')->where('student_registration_request_id', 20)->value('status'));
        self::assertSame(10, (int) DB::table('student_registration_request_items')->where('student_registration_request_item_id', 30)->value('student_course_registration_id'));
    }

    private function registrationService(): RegistrationService
    {
        return new RegistrationService(
            $this->createMock(AcademicRequirementService::class),
            $this->createMock(AcademicCalendarPolicyService::class),
            $this->createMock(GradeService::class),
            $this->createMock(CourseOfferingScheduleService::class),
        );
    }

    private function deadline(CourseRegistrationPhase $phase): CourseRegistrationDeadlineResult
    {
        return new CourseRegistrationDeadlineResult(
            $phase,
            1,
            1,
            CarbonImmutable::parse('2026-09-05T00:00:00Z'),
            CarbonImmutable::parse('2026-09-01T00:00:00Z'),
            CarbonImmutable::parse('2026-09-06T00:00:00Z'),
            CarbonImmutable::parse('2026-09-10T00:00:00Z'),
        );
    }

    private function approvedInitialRequest(bool $withItemLink = true): void
    {
        DB::table('student_registration_requests')->insert([
            'student_registration_request_id' => 20,
            'student_id' => 1,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'status' => 'approved',
            'approved_at' => '2026-09-01 00:00:00',
        ]);
        if ($withItemLink) {
            DB::table('student_registration_request_items')->insert([
                'student_registration_request_item_id' => 30,
                'student_registration_request_id' => 20,
                'course_offering_id' => 1,
                'student_course_registration_id' => 10,
            ]);
        }
    }

    private function student(): Student
    {
        return Student::query()->findOrFail(1);
    }

    private function registration(): StudentCourseRegistration
    {
        return StudentCourseRegistration::query()->findOrFail(10);
    }

    private function currentStatus(): string
    {
        return (string) DB::table('student_course_registrations as scr')
            ->join('registration_statuses as rs', 'rs.registration_status_id', '=', 'scr.registration_status_id')
            ->where('scr.student_course_registration_id', 10)
            ->value('rs.status_code');
    }

    private function expectRegistrationCode(callable $operation, string $code, int $status = 409): void
    {
        try {
            $operation();
            self::fail('Expected registration failure '.$code);
        } catch (RegistrationException $exception) {
            self::assertSame($code, $exception->errorCode);
            self::assertSame($status, $exception->status);
        }
    }

    private function seed(): void
    {
        DB::table('students')->insert(['student_id' => 1, 'student_number' => 'S1']);
        DB::table('users')->insert(['user_id' => 7, 'student_id' => 1, 'username' => 'student']);
        DB::table('users')->insert(['user_id' => 8, 'student_id' => null, 'username' => 'advisor']);
        DB::table('academic_years')->insert(['academic_year_id' => 1, 'year_name' => '2026-2027']);
        DB::table('semesters')->insert(['semester_id' => 1, 'semester_name' => 'First']);
        DB::table('courses')->insert(['course_id' => 1, 'course_code' => 'C101', 'course_name' => 'Course', 'credit_hours' => 3]);
        DB::table('course_offerings')->insert([
            'course_offering_id' => 1,
            'course_id' => 1,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'status' => 'open',
        ]);
        DB::table('registration_statuses')->insert([
            ['registration_status_id' => 1, 'status_code' => 'registered'],
            ['registration_status_id' => 2, 'status_code' => 'dropped'],
            ['registration_status_id' => 3, 'status_code' => 'withdrawn'],
        ]);
        DB::table('student_course_registrations')->insert([
            'student_course_registration_id' => 10,
            'student_id' => 1,
            'course_offering_id' => 1,
            'registration_status_id' => 1,
        ]);
    }

    private function schema(): void
    {
        Schema::create('students', function (Blueprint $table): void {
            $table->increments('student_id');
            $table->string('student_number')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('user_id');
            $table->integer('student_id')->nullable();
            $table->integer('employee_id')->nullable();
            $table->string('username')->nullable();
        });
        Schema::create('academic_years', function (Blueprint $table): void {
            $table->increments('academic_year_id');
            $table->string('year_name')->nullable();
        });
        Schema::create('semesters', function (Blueprint $table): void {
            $table->increments('semester_id');
            $table->string('semester_name')->nullable();
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->increments('course_id');
            $table->string('course_code');
            $table->string('course_name');
            $table->integer('credit_hours');
        });
        Schema::create('course_offerings', function (Blueprint $table): void {
            $table->increments('course_offering_id');
            $table->integer('course_id');
            $table->integer('academic_year_id');
            $table->integer('semester_id');
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('registration_statuses', function (Blueprint $table): void {
            $table->increments('registration_status_id');
            $table->string('status_code')->unique();
        });
        Schema::create('student_course_registrations', function (Blueprint $table): void {
            $table->increments('student_course_registration_id');
            $table->integer('student_id');
            $table->integer('course_offering_id');
            $table->integer('registration_status_id');
            $table->integer('result_status_id')->nullable();
            $table->timestamps();
        });
        Schema::create('student_registration_requests', function (Blueprint $table): void {
            $table->increments('student_registration_request_id');
            $table->integer('student_id');
            $table->integer('academic_year_id');
            $table->integer('semester_id');
            $table->string('status');
            $table->dateTime('approved_at')->nullable();
        });
        Schema::create('student_registration_request_items', function (Blueprint $table): void {
            $table->increments('student_registration_request_item_id');
            $table->integer('student_registration_request_id');
            $table->integer('course_offering_id');
            $table->integer('student_course_registration_id')->nullable();
        });
        Schema::create('student_registration_withdrawal_requests', function (Blueprint $table): void {
            $table->increments('student_registration_withdrawal_request_id');
            $table->integer('student_course_registration_id');
            $table->integer('current_slot')->nullable();
        });
        Schema::create('supplementary_exam_materializations', function (Blueprint $table): void {
            $table->increments('supplementary_exam_materialization_id');
            $table->integer('student_course_registration_id');
        });
        Schema::create('supplementary_exam_periods', function (Blueprint $table): void {
            $table->increments('supplementary_exam_period_id');
            $table->string('status');
        });
        Schema::create('supplementary_exam_offerings', function (Blueprint $table): void {
            $table->increments('supplementary_exam_offering_id');
            $table->integer('supplementary_exam_period_id');
        });
        Schema::create('supplementary_exam_registrations', function (Blueprint $table): void {
            $table->increments('supplementary_exam_registration_id');
            $table->integer('supplementary_exam_offering_id');
            $table->integer('student_course_registration_id');
            $table->string('status');
            $table->integer('current_slot')->nullable();
        });
    }

    private function phase5Schema(): void
    {
        Schema::create('student_registration_modification_requests', function (Blueprint $table): void {
            $table->increments('student_registration_modification_request_id');
            $table->integer('initial_registration_request_id');
            $table->integer('student_id');
            $table->integer('academic_year_id');
            $table->integer('semester_id');
            $table->string('status')->default('draft');
            $table->integer('submission_version')->default(0);
            $table->integer('current_slot')->nullable()->default(1);
            $table->text('student_notes')->nullable();
            $table->integer('advisor_user_id')->nullable();
            $table->text('advisor_notes')->nullable();
            $table->dateTime('first_submitted_at')->nullable();
            $table->dateTime('last_submitted_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('expired_at')->nullable();
            $table->dateTime('superseded_at')->nullable();
            $table->dateTime('materialized_at')->nullable();
            $table->integer('registered_hours_before_approval')->nullable();
            $table->integer('removed_hours_at_approval')->nullable();
            $table->integer('added_hours_at_approval')->nullable();
            $table->integer('projected_hours_at_approval')->nullable();
            $table->integer('max_allowed_hours_at_approval')->nullable();
            $table->integer('remaining_hours_after_approval')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'academic_year_id', 'semester_id', 'current_slot']);
        });
        Schema::create('student_registration_modification_items', function (Blueprint $table): void {
            $table->increments('student_registration_modification_item_id');
            $table->integer('student_registration_modification_request_id');
            $table->string('operation');
            $table->integer('course_offering_id');
            $table->integer('source_student_course_registration_id')->nullable();
            $table->integer('materialized_student_course_registration_id')->nullable();
            $table->timestamps();
        });
        Schema::create('student_registration_modification_events', function (Blueprint $table): void {
            $table->increments('student_registration_modification_event_id');
            $table->integer('student_registration_modification_request_id');
            $table->string('event_type');
            $table->integer('actor_user_id')->nullable();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->integer('submission_version')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('created_at');
        });
    }
}
