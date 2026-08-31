<?php

namespace Tests\Feature;

use App\Exceptions\RegistrationException;
use App\Services\AcademicCalendarPolicyService;
use App\Services\AcademicRequirementService;
use App\Services\GradeService;
use App\Services\RegistrationService;
use App\Models\StudentRegistrationRequest;
use App\Models\StudentRegistrationRequestItem;
use App\Support\AcademicCalendarPolicyResult;
use App\Support\AcademicCalendarPolicyStatus;
use App\Support\CourseRegistrationDeadlineResult;
use App\Support\CourseRegistrationPhase;
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
            [CourseRegistrationPhase::CLOSED, 'course_registration_closed', RegistrationException::COURSE_REGISTRATION_WINDOW_CLOSED],
            [CourseRegistrationPhase::CONFIGURATION_ERROR, 'course_registration_event_type_missing', RegistrationException::ACADEMIC_CALENDAR_CONFIGURATION_INVALID],
            [CourseRegistrationPhase::CONFIGURATION_ERROR, 'unknown_academic_year', RegistrationException::ACADEMIC_CALENDAR_YEAR_CONTEXT_INVALID],
            [CourseRegistrationPhase::CONFIGURATION_ERROR, 'unknown_semester', RegistrationException::ACADEMIC_CALENDAR_SEMESTER_CONTEXT_INVALID],
        ];

        foreach ($cases as [$phase, $reason, $errorCode]) {
            $policy = $this->createMock(AcademicCalendarPolicyService::class);
            $policy->expects($this->once())
                ->method('courseRegistrationDeadlines')
                ->with(1, 1, null)
                ->willReturn($this->deadlineResult($phase, $reason));

            try {
                $this->service($policy)->assertCourseRegistrationWindowOpen(1, 1);
                self::fail('Expected fail-closed registration deadline phase '.$phase->value);
            } catch (RegistrationException $exception) {
                self::assertSame(409, $exception->status);
                self::assertSame($errorCode, $exception->errorCode);
            }
        }

        $openPolicy = $this->createMock(AcademicCalendarPolicyService::class);
        $openPolicy->expects($this->once())
            ->method('courseRegistrationDeadlines')
            ->with(1, 1, null)
            ->willReturn($this->deadlineResult(CourseRegistrationPhase::STUDENT_OPEN));
        $this->service($openPolicy)->assertCourseRegistrationWindowOpen(1, 1);
        self::assertTrue(true);
    }

    public function test_every_materialization_gets_a_fresh_evaluation_with_locked_offering_context(): void
    {
        DB::table('students')->insert(['student_id' => 2, 'academic_program_id' => null]);
        $policy = $this->createMock(AcademicCalendarPolicyService::class);
        $policy->expects($this->exactly(2))
            ->method('courseRegistrationDeadlines')
            ->with(1, 1, null)
            ->willReturn($this->deadlineResult(CourseRegistrationPhase::STUDENT_OPEN));
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
        self::assertSame(2, (int) DB::table('course_offerings')->where('course_offering_id', 1)->value('available_seats'));
    }

    public function test_trusted_advisor_materialization_reuses_all_registration_rules_without_the_student_cutoff(): void
    {
        DB::table('student_registration_requests')->insert([
            'student_registration_request_id' => 1,
            'student_id' => 1,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'status' => 'submitted',
            'submission_version' => 1,
            'last_submitted_at' => '2026-09-04 00:00:00',
        ]);
        DB::table('student_registration_request_items')->insert([
            'student_registration_request_item_id' => 1,
            'student_registration_request_id' => 1,
            'course_offering_id' => 1,
        ]);
        $policy = $this->createMock(AcademicCalendarPolicyService::class);
        $policy->expects($this->once())
            ->method('courseRegistrationDeadlines')
            ->willReturn($this->deadlineResult(CourseRegistrationPhase::ADVISOR_REVIEW));

        DB::transaction(fn () => $this->service($policy)->materializeAdvisorApprovedRequestItemWithinTransaction(
            StudentRegistrationRequest::query()->findOrFail(1),
            StudentRegistrationRequestItem::query()->findOrFail(1),
            7,
            CarbonImmutable::parse('2026-09-05T00:00:00Z'),
        ));

        self::assertSame(1, DB::table('student_course_registrations')->count());
        self::assertSame(2, (int) DB::table('course_offerings')->where('course_offering_id', 1)->value('available_seats'));
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
            ->method('courseRegistrationDeadlines')
            ->with(1, 1, null)
            ->willReturn($this->deadlineResult(CourseRegistrationPhase::STUDENT_OPEN));

        DB::transaction(fn () => $this->service($policy)->registerStudentWithinTransaction([
            'student_id' => 1,
            'course_offering_id' => 1,
        ], 7));

        self::assertSame(1, DB::table('student_course_registrations')->count());
        self::assertSame(1, (int) DB::table('student_course_registrations')->where('student_course_registration_id', 10)->value('registration_status_id'));
        self::assertSame(2, (int) DB::table('course_offerings')->where('course_offering_id', 1)->value('available_seats'));
    }

    public function test_real_student_deadline_policy_requires_an_exact_semester_root(): void
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
        $this->expectRegistrationCode(
            fn () => $this->register(),
            RegistrationException::ACADEMIC_CALENDAR_CONFIGURATION_INVALID,
        );
        self::assertSame(0, DB::table('student_course_registrations')->count());

        $this->resetRegistration();
        DB::table('course_offerings')->where('course_offering_id', 1)->update(['academic_year_id' => 2]);
        $this->expectRegistrationCode(
            fn () => $this->register(),
            RegistrationException::ACADEMIC_CALENDAR_YEAR_CONTEXT_INVALID,
        );
    }

    public function test_year_wide_window_opens_each_eligible_explicit_semester_context(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03T12:00:00Z'));
        $this->createWindow(semesterId: null);
        $service = $this->service();

        self::assertTrue($service->courseRegistrationWindow(1, 1)->isOpen());
        self::assertTrue($service->courseRegistrationWindow(1, 2)->isOpen());
    }

    public function test_non_effective_calendar_revisions_never_authorize_registration(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03T12:00:00Z'));

        $this->createWindow(isEnforcement: false);
        $this->expectRegistrationCode(fn () => $this->register(), RegistrationException::ACADEMIC_CALENDAR_CONFIGURATION_INVALID);
        $this->clearWindows();

        $this->createWindow(cancelled: true);
        $this->expectRegistrationCode(fn () => $this->register(), RegistrationException::ACADEMIC_CALENDAR_CONFIGURATION_INVALID);
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
        $service = $this->service();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03T12:00:00Z'));
        self::assertTrue($service->courseRegistrationWindow(1, 1)->isOpen());
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07T12:00:00Z'));
        self::assertFalse($service->courseRegistrationWindow(1, 1)->isOpen());
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12T12:00:00Z'));
        self::assertTrue($service->courseRegistrationWindow(1, 1)->isOpen());
    }

    public function test_closed_gate_preserves_dropped_row_and_available_seats(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03T12:00:00Z'));
        $this->createWindow(startsAt: '2026-09-01 00:00:00', endsAt: '2026-09-02 23:59:59');
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

    public function test_open_gate_preserves_duplicate_prerequisite_credit_and_offering_rules_without_seat_policy(): void
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
        $this->register();
        self::assertSame(0, (int) DB::table('course_offerings')->where('course_offering_id', 1)->value('available_seats'));
        $this->resetRegistration();

        DB::table('course_prerequisites')->insert(['course_id' => 1, 'prerequisite_course_id' => 2]);
        $this->expectRegistrationMessage(fn () => $this->register(), 'missing prerequisites');
        DB::table('course_prerequisites')->delete();

        DB::table('student_credit_limits')->insert([
            'student_id' => 1,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'max_credit_hours' => 2,
        ]);
        $this->register();
        self::assertSame(1, DB::table('student_course_registrations')->count());
        $this->resetRegistration();
        DB::table('student_credit_limits')->delete();

        DB::table('course_offerings')->where('course_offering_id', 1)->update(['status' => 'closed']);
        $this->expectRegistrationMessage(fn () => $this->register(), 'not open for registration');
    }

    public function test_phase3_credit_cap_uses_only_the_official_grade_service_cgpa(): void
    {
        foreach ([null => 18, '0' => 18, '2.999' => 18, '3.0' => 21, '3.75' => 21] as $cgpa => $expected) {
            $metrics = [
                'cumulative_gpa' => $cgpa === '' ? null : (is_numeric($cgpa) ? (float) $cgpa : null),
                'official_completed_courses' => [],
            ];
            $hours = $this->service(metrics: $metrics)->hoursSnapshot(
                \App\Models\Student::query()->findOrFail(1),
                1,
                1,
            );

            self::assertSame($expected, $hours['max_allowed_hours']);
            self::assertSame(12, $hours['recommended_minimum_hours']);
        }
    }

    public function test_phase3_official_pass_blocks_repeat_and_satisfies_prerequisite_by_course_id(): void
    {
        $student = \App\Models\Student::query()->findOrFail(1);
        $metrics = [
            'cumulative_gpa' => 3.1,
            'official_completed_courses' => [[
                'course_id' => 2,
                'course_code' => 'PRE-2',
                'course_name' => 'Official prerequisite',
            ]],
        ];
        $service = $this->service(metrics: $metrics);

        DB::table('course_prerequisites')->insert([
            'course_id' => 1,
            'prerequisite_course_id' => 2,
        ]);

        self::assertTrue($service->hasPassedCourse($student, 2));
        self::assertSame([], $service->getMissingPrerequisites($student, 1));
    }

    public function test_phase3_failed_incomplete_deprived_or_unapproved_attempts_do_not_satisfy_prerequisites(): void
    {
        DB::table('course_prerequisites')->insert([
            'course_id' => 1,
            'prerequisite_course_id' => 2,
        ]);
        $student = \App\Models\Student::query()->findOrFail(1);
        $service = $this->service(metrics: [
            'cumulative_gpa' => 3.4,
            'official_completed_courses' => [],
        ]);

        self::assertFalse($service->hasPassedCourse($student, 2));
        self::assertSame([
            [
                'course_id' => 2,
                'course_code' => 'C100',
                'course_name' => 'Prerequisite',
            ],
        ], $service->getMissingPrerequisites($student, 1));
    }

    public function test_phase3_officially_passed_course_blocks_normal_materialization_by_course_id(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03T12:00:00Z'));
        $this->createWindow();
        DB::table('course_offerings')->insert([
            'course_offering_id' => 2,
            'course_id' => 1,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'capacity' => 0,
            'available_seats' => 0,
            'status' => 'open',
        ]);
        $service = $this->service(metrics: [
            'cumulative_gpa' => 3.25,
            'official_completed_courses' => [['course_id' => 1]],
        ]);

        $this->expectRegistrationCode(
            fn () => DB::transaction(fn () => $service->registerStudentWithinTransaction([
                'student_id' => 1,
                'course_offering_id' => 2,
            ], 7)),
            RegistrationException::COURSE_ALREADY_PASSED,
        );

        self::assertSame(0, DB::table('student_course_registrations')->count());
        self::assertSame(0, (int) DB::table('course_offerings')->where('course_offering_id', 2)->value('available_seats'));
    }

    public function test_phase3_exact_eighteen_and_twenty_one_hour_caps_ignore_legacy_credit_rows(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03T12:00:00Z'));
        $this->createWindow();
        DB::table('courses')->insert(collect(range(3, 8))->map(fn (int $id): array => [
            'course_id' => $id,
            'course_code' => 'C'.$id,
            'course_name' => 'Course '.$id,
            'credit_hours' => 3,
        ])->all());
        DB::table('course_offerings')->insert(collect(range(2, 8))->map(fn (int $id): array => [
            'course_offering_id' => $id,
            'course_id' => $id,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'capacity' => 0,
            'available_seats' => 0,
            'status' => 'open',
        ])->all());
        DB::table('student_credit_limits')->insert([
            'student_id' => 1,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'max_credit_hours' => 30,
            'is_excellent_student' => 1,
        ]);

        $ordinary = $this->service(metrics: [
            'cumulative_gpa' => 2.99,
            'official_completed_courses' => [],
        ]);
        foreach (range(1, 6) as $offeringId) {
            DB::transaction(fn () => $ordinary->registerStudentWithinTransaction([
                'student_id' => 1,
                'course_offering_id' => $offeringId,
            ], 7));
        }
        self::assertSame(18, $ordinary->hoursSnapshot(\App\Models\Student::query()->findOrFail(1), 1, 1)['registered_hours']);
        $this->expectRegistrationMessage(
            fn () => DB::transaction(fn () => $ordinary->registerStudentWithinTransaction([
                'student_id' => 1,
                'course_offering_id' => 7,
            ], 7)),
            'Credit hour limit',
        );

        DB::table('student_course_registrations')->delete();
        DB::table('student_credit_limits')->update(['max_credit_hours' => 15]);
        $highCgpa = $this->service(metrics: [
            'cumulative_gpa' => 3.0,
            'official_completed_courses' => [],
        ]);
        foreach (range(1, 7) as $offeringId) {
            DB::transaction(fn () => $highCgpa->registerStudentWithinTransaction([
                'student_id' => 1,
                'course_offering_id' => $offeringId,
            ], 7));
        }
        self::assertSame(21, $highCgpa->hoursSnapshot(\App\Models\Student::query()->findOrFail(1), 1, 1)['registered_hours']);
        $this->expectRegistrationMessage(
            fn () => DB::transaction(fn () => $highCgpa->registerStudentWithinTransaction([
                'student_id' => 1,
                'course_offering_id' => 8,
            ], 7)),
            'Credit hour limit',
        );
        self::assertSame(0, (int) DB::table('course_offerings')->where('course_offering_id', 2)->value('available_seats'));
    }

    public function test_phase3_non_passed_historical_attempt_allows_normal_retry_in_a_new_offering(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03T12:00:00Z'));
        $this->createWindow();
        DB::table('course_offerings')->insert([
            'course_offering_id' => 2,
            'course_id' => 1,
            'academic_year_id' => 2,
            'semester_id' => 2,
            'capacity' => 0,
            'available_seats' => 0,
            'status' => 'closed',
        ]);
        DB::table('student_course_registrations')->insert([
            'student_id' => 1,
            'course_offering_id' => 2,
            'registration_status_id' => 4,
        ]);

        DB::transaction(fn () => $this->service(metrics: [
            'cumulative_gpa' => 2.5,
            'official_completed_courses' => [],
        ])->registerStudentWithinTransaction([
            'student_id' => 1,
            'course_offering_id' => 1,
        ], 7));

        self::assertSame(2, DB::table('student_course_registrations')->count());
        self::assertSame(2, (int) DB::table('course_offerings')->where('course_offering_id', 1)->value('available_seats'));
    }

    public function test_closed_window_has_no_role_bypass_and_does_not_block_reads(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03T12:00:00Z'));
        $this->createWindow(startsAt: '2026-09-01 00:00:00', endsAt: '2026-09-02 23:59:59');
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

    private function service(
        ?AcademicCalendarPolicyService $policy = null,
        ?array $metrics = null,
    ): RegistrationService
    {
        $requirements = Mockery::mock(AcademicRequirementService::class);
        $requirements->shouldReceive('assertRegistrationCandidateAllowed')->zeroOrMoreTimes();
        $grades = Mockery::mock(GradeService::class);
        $grades->shouldReceive('officialCumulativeMetrics')->zeroOrMoreTimes()->andReturn($metrics ?? [
            'cumulative_gpa' => null,
            'official_completed_courses' => [],
        ]);

        return new RegistrationService(
            $requirements,
            $policy ?? app(AcademicCalendarPolicyService::class),
            $grades,
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

    private function deadlineResult(CourseRegistrationPhase $phase, ?string $reason = null): CourseRegistrationDeadlineResult
    {
        return new CourseRegistrationDeadlineResult(
            $phase,
            1,
            1,
            CarbonImmutable::parse('2026-09-03T12:00:00Z'),
            CarbonImmutable::parse('2026-09-01T00:00:00Z'),
            CarbonImmutable::parse('2026-09-04T00:00:00Z'),
            CarbonImmutable::parse('2026-09-05T00:00:00Z'),
            reasonCode: $reason,
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
            ['registration_status_id' => 4, 'status_code' => 'completed'],
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
            $table->dateTime('student_registration_ends_at')->nullable();
            $table->dateTime('advisor_approval_ends_at')->nullable();
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
            $table->boolean('is_excellent_student')->default(false);
        });
        Schema::create('student_registration_requests', function (Blueprint $table): void {
            $table->increments('student_registration_request_id');
            $table->integer('student_id');
            $table->integer('academic_year_id');
            $table->integer('semester_id');
            $table->string('status');
            $table->integer('submission_version')->default(0);
            $table->dateTime('last_submitted_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('expired_at')->nullable();
        });
        Schema::create('student_registration_request_items', function (Blueprint $table): void {
            $table->increments('student_registration_request_item_id');
            $table->integer('student_registration_request_id');
            $table->integer('course_offering_id');
            $table->integer('student_course_registration_id')->nullable();
        });
    }
}
