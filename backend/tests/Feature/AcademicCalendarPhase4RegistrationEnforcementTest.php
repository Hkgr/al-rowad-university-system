<?php

namespace Tests\Feature;

use App\Exceptions\RegistrationException;
use App\Services\AcademicCalendarPolicyService;
use App\Services\AcademicRequirementService;
use App\Services\RegistrationService;
use App\Support\AcademicCalendarPolicyResult;
use App\Support\AcademicCalendarPolicyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class AcademicCalendarPhase4RegistrationEnforcementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->createSchema();
        $this->seedBaseData();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_typed_policy_statuses_map_to_four_distinct_registration_errors(): void
    {
        $cases = [
            [AcademicCalendarPolicyStatus::CLOSED, RegistrationException::COURSE_REGISTRATION_WINDOW_CLOSED],
            [AcademicCalendarPolicyStatus::INVALID_EVENT_TYPE, RegistrationException::ACADEMIC_CALENDAR_CONFIGURATION_INVALID],
            [AcademicCalendarPolicyStatus::CALENDAR_CONFIGURATION_ERROR, RegistrationException::ACADEMIC_CALENDAR_CONFIGURATION_INVALID],
            [AcademicCalendarPolicyStatus::INVALID_ACADEMIC_YEAR, RegistrationException::ACADEMIC_CALENDAR_YEAR_CONTEXT_INVALID],
            [AcademicCalendarPolicyStatus::INVALID_SEMESTER_CONTEXT, RegistrationException::ACADEMIC_CALENDAR_SEMESTER_CONTEXT_INVALID],
        ];

        foreach ($cases as [$status, $errorCode]) {
            $policy = $this->createMock(AcademicCalendarPolicyService::class);
            $policy->expects($this->once())
                ->method('evaluate')
                ->with('course_registration', 1, 1)
                ->willReturn($this->policyResult($status));

            try {
                $this->service($policy)->assertCourseRegistrationWindowOpen(1, 1);
                self::fail('Expected fail-closed registration policy status '.$status->value);
            } catch (RegistrationException $exception) {
                self::assertSame(409, $exception->status);
                self::assertSame($errorCode, $exception->errorCode);
            }
        }

        $openPolicy = $this->createMock(AcademicCalendarPolicyService::class);
        $openPolicy->expects($this->once())
            ->method('evaluate')
            ->with('course_registration', 1, 1)
            ->willReturn($this->policyResult(AcademicCalendarPolicyStatus::OPEN));
        $this->service($openPolicy)->assertCourseRegistrationWindowOpen(1, 1);
        self::assertTrue(true);
    }

    public function test_every_materialization_gets_a_fresh_evaluation_with_locked_offering_context(): void
    {
        DB::table('students')->insert(['student_id' => 2, 'academic_program_id' => null]);
        $policy = $this->createMock(AcademicCalendarPolicyService::class);
        $policy->expects($this->exactly(2))
            ->method('evaluate')
            ->with('course_registration', 1, 1)
            ->willReturn($this->policyResult(AcademicCalendarPolicyStatus::OPEN));
        $service = $this->service($policy);

        DB::transaction(fn () => $service->registerStudentWithinTransaction([
            'student_id' => 1,
            'course_offering_id' => 1,
        ], 7));
        DB::transaction(fn () => $service->registerStudentWithinTransaction([
            'student_id' => 2,
            'course_offering_id' => 1,
        ], 7));

        self::assertSame(2, DB::table('student_course_registrations')->count());
        self::assertSame(0, (int) DB::table('course_offerings')->where('course_offering_id', 1)->value('available_seats'));
    }

    public function test_real_policy_wiring_honors_inclusive_boundaries_and_rejects_outside_seconds(): void
    {
        $this->createWindow(startsAt: '2026-09-01 08:00:00', endsAt: '2026-09-05 16:00:00');

        $this->attemptAt('2026-09-01T08:00:00Z', succeeds: true);
        $this->attemptAt('2026-09-05T16:00:00Z', succeeds: true);
        $this->attemptAt('2026-09-01T07:59:59Z', succeeds: false);
        $this->attemptAt('2026-09-05T16:00:01Z', succeeds: false);
    }

    public function test_dropped_registration_reactivation_receives_its_own_final_policy_evaluation(): void
    {
        DB::table('student_course_registrations')->insert([
            'student_course_registration_id' => 10,
            'student_id' => 1,
            'course_offering_id' => 1,
            'registration_status_id' => 2,
        ]);
        $policy = $this->createMock(AcademicCalendarPolicyService::class);
        $policy->expects($this->once())
            ->method('evaluate')
            ->with('course_registration', 1, 1)
            ->willReturn($this->policyResult(AcademicCalendarPolicyStatus::OPEN));

        DB::transaction(fn () => $this->service($policy)->registerStudentWithinTransaction([
            'student_id' => 1,
            'course_offering_id' => 1,
        ], 7));

        self::assertSame(1, DB::table('student_course_registrations')->count());
        self::assertSame(1, (int) DB::table('student_course_registrations')->where('student_course_registration_id', 10)->value('registration_status_id'));
        self::assertSame(1, (int) DB::table('course_offerings')->where('course_offering_id', 1)->value('available_seats'));
    }

    public function test_real_policy_uses_explicit_year_semester_and_year_wide_wildcard(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03T12:00:00Z'));
        $this->createWindow(semesterId: 2);
        $this->expectRegistrationCode(
            fn () => $this->register(),
            RegistrationException::COURSE_REGISTRATION_WINDOW_CLOSED,
        );

        DB::table('academic_calendar_events')->delete();
        DB::table('academic_calendar_event_versions')->delete();
        $this->createWindow(semesterId: null);
        $this->register();
        self::assertSame(1, DB::table('student_course_registrations')->count());

        $this->resetRegistration();
        DB::table('course_offerings')->where('course_offering_id', 1)->update(['academic_year_id' => 2]);
        $this->expectRegistrationCode(
            fn () => $this->register(),
            RegistrationException::ACADEMIC_CALENDAR_YEAR_CONTEXT_INVALID,
        );
    }

    public function test_non_effective_calendar_revisions_never_authorize_registration(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03T12:00:00Z'));

        $this->createWindow(isEnforcement: false);
        $this->expectClosedRegistration();
        $this->clearWindows();

        $this->createWindow(cancelled: true);
        $this->expectClosedRegistration();
        $this->clearWindows();

        $eventId = $this->createWindow(publicationStatus: 'superseded', supersededAt: '2026-08-25 00:00:00');
        $this->addVersion($eventId, [
            'version_number' => 2,
            'starts_at' => '2026-09-15 00:00:00',
            'ends_at' => '2026-09-18 23:59:59',
        ]);
        $this->expectClosedRegistration();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-16T12:00:00Z'));
        $this->register();
        self::assertSame(1, DB::table('student_course_registrations')->count());
    }

    public function test_multiple_windows_use_any_match_and_do_not_bridge_a_gap(): void
    {
        $this->createWindow(startsAt: '2026-09-01 00:00:00', endsAt: '2026-09-05 23:59:59');
        $this->createWindow(startsAt: '2026-09-10 00:00:00', endsAt: '2026-09-15 23:59:59');

        $this->attemptAt('2026-09-03T12:00:00Z', succeeds: true);
        $this->attemptAt('2026-09-07T12:00:00Z', succeeds: false);
        $this->attemptAt('2026-09-12T12:00:00Z', succeeds: true);
    }

    public function test_closed_gate_preserves_dropped_row_and_available_seats(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03T12:00:00Z'));
        DB::table('student_course_registrations')->insert([
            'student_course_registration_id' => 10,
            'student_id' => 1,
            'course_offering_id' => 1,
            'registration_status_id' => 2,
        ]);

        $this->expectClosedRegistration();

        self::assertSame(2, (int) DB::table('student_course_registrations')->where('student_course_registration_id', 10)->value('registration_status_id'));
        self::assertSame(2, (int) DB::table('course_offerings')->where('course_offering_id', 1)->value('available_seats'));
    }

    public function test_open_gate_preserves_existing_duplicate_seat_prerequisite_credit_and_offering_rules(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03T12:00:00Z'));
        $this->createWindow();

        DB::table('student_course_registrations')->insert([
            'student_id' => 1,
            'course_offering_id' => 1,
            'registration_status_id' => 1,
        ]);
        $this->expectRegistrationMessage(fn () => $this->register(), 'already registered');
        $this->resetRegistration();

        DB::table('course_offerings')->where('course_offering_id', 1)->update(['available_seats' => 0]);
        $this->expectRegistrationMessage(fn () => $this->register(), 'No available seats');
        DB::table('course_offerings')->where('course_offering_id', 1)->update(['available_seats' => 2]);

        DB::table('course_prerequisites')->insert(['course_id' => 1, 'prerequisite_course_id' => 2]);
        $this->expectRegistrationMessage(fn () => $this->register(), 'missing prerequisites');
        DB::table('course_prerequisites')->delete();

        DB::table('student_credit_limits')->insert([
            'student_id' => 1,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'max_credit_hours' => 2,
        ]);
        $this->expectRegistrationMessage(fn () => $this->register(), 'Credit hour limit');
        DB::table('student_credit_limits')->delete();

        DB::table('course_offerings')->where('course_offering_id', 1)->update(['status' => 'closed']);
        $this->expectRegistrationMessage(fn () => $this->register(), 'not open for registration');
    }

    public function test_closed_window_has_no_role_bypass_and_does_not_block_reads(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03T12:00:00Z'));
        foreach ([7, 999] as $actorId) {
            $this->expectRegistrationCode(
                fn () => $this->register($actorId),
                RegistrationException::COURSE_REGISTRATION_WINDOW_CLOSED,
            );
        }

        DB::table('student_course_registrations')->insert([
            'student_course_registration_id' => 20,
            'student_id' => 1,
            'course_offering_id' => 1,
            'registration_status_id' => 1,
        ]);
        $registration = $this->service()->findOrFail(20);
        self::assertSame(20, (int) $registration->student_course_registration_id);
        self::assertSame(2, (int) DB::table('course_offerings')->where('course_offering_id', 1)->value('available_seats'));
    }

    public function test_multi_item_materialization_rolls_back_registrations_seats_and_request_state(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03T12:00:00Z'));
        $this->createWindow(semesterId: 1);
        DB::table('course_offerings')->insert([
            'course_offering_id' => 2,
            'course_id' => 2,
            'academic_year_id' => 1,
            'semester_id' => 2,
            'capacity' => 1,
            'available_seats' => 1,
            'status' => 'open',
        ]);
        DB::table('student_registration_requests')->insert([
            'student_registration_request_id' => 1,
            'student_id' => 1,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'status' => 'submitted',
        ]);
        $service = $this->service();

        $this->expectRegistrationCode(function () use ($service): void {
            DB::transaction(function () use ($service): void {
                $service->registerStudentWithinTransaction(['student_id' => 1, 'course_offering_id' => 1], 7);
                $service->registerStudentWithinTransaction(['student_id' => 1, 'course_offering_id' => 2], 7);
                DB::table('student_registration_requests')->where('student_registration_request_id', 1)->update(['status' => 'approved']);
            });
        }, RegistrationException::COURSE_REGISTRATION_WINDOW_CLOSED);

        self::assertSame(0, DB::table('student_course_registrations')->count());
        self::assertSame(2, (int) DB::table('course_offerings')->where('course_offering_id', 1)->value('available_seats'));
        self::assertSame(1, (int) DB::table('course_offerings')->where('course_offering_id', 2)->value('available_seats'));
        self::assertSame('submitted', DB::table('student_registration_requests')->where('student_registration_request_id', 1)->value('status'));
    }

    public function test_physical_calendar_schema_failures_are_not_normalized_to_registration_policy_codes(): void
    {
        Schema::drop('academic_calendar_event_types');
        $this->expectException(QueryException::class);
        $this->register();
    }

    private function service(?AcademicCalendarPolicyService $policy = null): RegistrationService
    {
        $requirements = Mockery::mock(AcademicRequirementService::class);
        $requirements->shouldReceive('assertRegistrationCandidateAllowed')->zeroOrMoreTimes();

        return new RegistrationService(
            $requirements,
            $policy ?? app(AcademicCalendarPolicyService::class),
        );
    }

    private function register(int $actorId = 7): array
    {
        return DB::transaction(fn () => $this->service()->registerStudentWithinTransaction([
            'student_id' => 1,
            'course_offering_id' => 1,
        ], $actorId));
    }

    private function attemptAt(string $at, bool $succeeds): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($at));
        if ($succeeds) {
            $this->register();
            self::assertSame(1, DB::table('student_course_registrations')->count());
            $this->resetRegistration();

            return;
        }

        $this->expectClosedRegistration();
        self::assertSame(0, DB::table('student_course_registrations')->count());
        self::assertSame(2, (int) DB::table('course_offerings')->where('course_offering_id', 1)->value('available_seats'));
    }

    private function expectClosedRegistration(): void
    {
        $this->expectRegistrationCode(
            fn () => $this->register(),
            RegistrationException::COURSE_REGISTRATION_WINDOW_CLOSED,
        );
    }

    private function expectRegistrationCode(callable $operation, string $errorCode): void
    {
        try {
            $operation();
            self::fail('Expected registration error '.$errorCode);
        } catch (RegistrationException $exception) {
            self::assertSame($errorCode, $exception->errorCode);
        }
    }

    private function expectRegistrationMessage(callable $operation, string $fragment): void
    {
        try {
            $operation();
            self::fail('Expected registration rule failure containing '.$fragment);
        } catch (RegistrationException $exception) {
            self::assertStringContainsString($fragment, $exception->getMessage());
        }
    }

    private function resetRegistration(): void
    {
        DB::table('student_course_registrations')->delete();
        DB::table('course_offerings')->where('course_offering_id', 1)->update([
            'available_seats' => 2,
            'status' => 'open',
            'academic_year_id' => 1,
            'semester_id' => 1,
        ]);
    }

    private function clearWindows(): void
    {
        DB::table('academic_calendar_event_versions')->delete();
        DB::table('academic_calendar_events')->delete();
    }

    private function createWindow(
        ?int $semesterId = 1,
        string $startsAt = '2026-09-01 00:00:00',
        string $endsAt = '2026-09-05 23:59:59',
        bool $isEnforcement = true,
        bool $cancelled = false,
        string $publicationStatus = 'published',
        ?string $supersededAt = null,
    ): int {
        $eventId = DB::table('academic_calendar_events')->insertGetId([
            'academic_year_id' => 1,
            'semester_id' => $semesterId,
            'academic_calendar_event_type_id' => 1,
            'created_by_user_id' => 7,
            'created_at' => '2026-08-20 00:00:00',
            'cancelled_by_user_id' => $cancelled ? 7 : null,
            'cancelled_at' => $cancelled ? '2026-08-25 00:00:00' : null,
            'cancellation_reason' => $cancelled ? 'cancelled fixture' : null,
        ]);
        $this->addVersion($eventId, [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_enforcement' => $isEnforcement,
            'publication_status' => $publicationStatus,
            'superseded_at' => $supersededAt,
        ]);

        return $eventId;
    }

    private function addVersion(int $eventId, array $overrides = []): int
    {
        return DB::table('academic_calendar_event_versions')->insertGetId(array_merge([
            'academic_calendar_event_id' => $eventId,
            'version_number' => 1,
            'replaces_version_id' => null,
            'title' => 'Course registration window',
            'starts_at' => '2026-09-01 00:00:00',
            'ends_at' => '2026-09-05 23:59:59',
            'is_enforcement' => true,
            'change_reason' => 'Phase 4 test fixture',
            'created_by_user_id' => 7,
            'created_at' => '2026-08-20 00:00:00',
            'publication_status' => 'published',
            'published_by_user_id' => 7,
            'published_at' => '2026-08-20 00:00:00',
            'superseded_at' => null,
            'published_event_slot' => null,
        ], $overrides));
    }

    private function policyResult(AcademicCalendarPolicyStatus $status): AcademicCalendarPolicyResult
    {
        return new AcademicCalendarPolicyResult(
            $status,
            'course_registration',
            1,
            1,
            CarbonImmutable::parse('2026-09-03T12:00:00Z'),
            $status === AcademicCalendarPolicyStatus::OPEN ? 1 : 0,
        );
    }

    private function seedBaseData(): void
    {
        DB::table('academic_years')->insert([
            ['academic_year_id' => 1, 'is_current' => 1, 'is_active' => 1, 'calendar_lifecycle_status' => 'active'],
            ['academic_year_id' => 2, 'is_current' => 0, 'is_active' => 1, 'calendar_lifecycle_status' => 'draft'],
        ]);
        DB::table('semesters')->insert([
            ['semester_id' => 1, 'semester_code' => 'first', 'semester_order' => 1, 'is_active' => 1],
            ['semester_id' => 2, 'semester_code' => 'second', 'semester_order' => 2, 'is_active' => 1],
        ]);
        DB::table('academic_calendar_event_types')->insert([
            'academic_calendar_event_type_id' => 1,
            'event_type_code' => 'course_registration',
            'is_active' => 1,
        ]);
        DB::table('students')->insert(['student_id' => 1, 'academic_program_id' => null]);
        DB::table('courses')->insert([
            ['course_id' => 1, 'course_code' => 'C101', 'course_name' => 'Course 1', 'credit_hours' => 3],
            ['course_id' => 2, 'course_code' => 'C100', 'course_name' => 'Prerequisite', 'credit_hours' => 3],
        ]);
        DB::table('course_offerings')->insert([
            'course_offering_id' => 1,
            'course_id' => 1,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'capacity' => 2,
            'available_seats' => 2,
            'status' => 'open',
        ]);
        DB::table('registration_statuses')->insert([
            ['registration_status_id' => 1, 'status_code' => 'registered'],
            ['registration_status_id' => 2, 'status_code' => 'dropped'],
            ['registration_status_id' => 3, 'status_code' => 'withdrawn'],
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('academic_years', function (Blueprint $table): void {
            $table->increments('academic_year_id');
            $table->boolean('is_current');
            $table->boolean('is_active');
            $table->string('calendar_lifecycle_status', 16);
        });
        Schema::create('semesters', function (Blueprint $table): void {
            $table->increments('semester_id');
            $table->string('semester_code');
            $table->integer('semester_order')->default(1);
            $table->boolean('is_active');
        });
        Schema::create('academic_calendar_event_types', function (Blueprint $table): void {
            $table->increments('academic_calendar_event_type_id');
            $table->string('event_type_code')->unique();
            $table->boolean('is_active');
        });
        Schema::create('academic_calendar_events', function (Blueprint $table): void {
            $table->increments('academic_calendar_event_id');
            $table->integer('academic_year_id');
            $table->integer('semester_id')->nullable();
            $table->integer('academic_calendar_event_type_id');
            $table->integer('created_by_user_id');
            $table->dateTime('created_at');
            $table->integer('cancelled_by_user_id')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
        });
        Schema::create('academic_calendar_event_versions', function (Blueprint $table): void {
            $table->increments('academic_calendar_event_version_id');
            $table->integer('academic_calendar_event_id');
            $table->integer('version_number');
            $table->integer('replaces_version_id')->nullable();
            $table->string('title');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('is_enforcement');
            $table->text('change_reason');
            $table->integer('created_by_user_id');
            $table->dateTime('created_at');
            $table->string('publication_status', 16);
            $table->integer('published_by_user_id')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('superseded_at')->nullable();
            $table->integer('published_event_slot')->nullable();
        });
        Schema::create('students', function (Blueprint $table): void {
            $table->increments('student_id');
            $table->integer('academic_program_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
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
            $table->integer('capacity');
            $table->integer('available_seats');
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
            $table->date('registration_date')->nullable();
            $table->integer('registered_by_user_id')->nullable();
            $table->integer('advisor_user_id')->nullable();
            $table->integer('registration_status_id')->nullable();
            $table->integer('result_status_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'course_offering_id']);
        });
        Schema::create('course_prerequisites', function (Blueprint $table): void {
            $table->increments('course_prerequisite_id');
            $table->integer('course_id');
            $table->integer('prerequisite_course_id');
        });
        Schema::create('student_credit_limits', function (Blueprint $table): void {
            $table->increments('student_credit_limit_id');
            $table->integer('student_id');
            $table->integer('academic_year_id');
            $table->integer('semester_id');
            $table->integer('max_credit_hours');
        });
        Schema::create('student_registration_requests', function (Blueprint $table): void {
            $table->increments('student_registration_request_id');
            $table->integer('student_id');
            $table->integer('academic_year_id');
            $table->integer('semester_id');
            $table->string('status');
        });
    }
}
