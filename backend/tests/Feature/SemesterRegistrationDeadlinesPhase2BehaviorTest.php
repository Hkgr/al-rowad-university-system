<?php

namespace Tests\Feature;

use App\Exceptions\AcademicCalendarException;
use App\Exceptions\RegistrationException;
use App\Exceptions\RegistrationRequestException;
use App\Models\AcademicYear;
use App\Models\AcademicCalendarEventType;
use App\Models\CourseOffering;
use App\Models\Student;
use App\Models\StudentRegistrationRequest;
use App\Models\StudentRegistrationRequestItem;
use App\Models\User;
use App\Services\AcademicCalendarService;
use App\Services\AcademicCalendarPolicyService;
use App\Services\AcademicRequirementService;
use App\Services\AcademicTermResolver;
use App\Services\CourseOfferingInstructorCoverageService;
use App\Services\CourseOfferingScheduleService;
use App\Services\DataScopeService;
use App\Services\GradeService;
use App\Services\RegistrationRequestService;
use App\Services\RegistrationService;
use App\Services\TeachingAssignmentService;
use App\Support\CourseRegistrationDeadlineResult;
use App\Support\CourseRegistrationPhase;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class SemesterRegistrationDeadlinesPhase2BehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->schema();
        $this->seedOperationalContext();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @dataProvider phaseBoundaries */
    public function test_deadline_phases_use_inclusive_student_and_advisor_boundaries(string $at, CourseRegistrationPhase $phase): void
    {
        $this->registrationWindow(
            startsAt: '2026-09-01 08:00:00',
            studentEndsAt: '2026-09-05 16:00:00',
            advisorEndsAt: '2026-09-07 16:00:00',
        );

        $result = app(AcademicCalendarPolicyService::class)->courseRegistrationDeadlines(
            1,
            1,
            CarbonImmutable::parse($at),
        );

        self::assertSame($phase, $result->phase);
        self::assertSame('2026-09-05T16:00:00+00:00', $result->studentRegistrationEndsAt?->toIso8601String());
        self::assertSame('2026-09-07T16:00:00+00:00', $result->advisorApprovalEndsAt?->toIso8601String());
    }

    public static function phaseBoundaries(): array
    {
        return [
            'before start' => ['2026-09-01T07:59:59Z', CourseRegistrationPhase::NOT_STARTED],
            'at start' => ['2026-09-01T08:00:00Z', CourseRegistrationPhase::STUDENT_OPEN],
            'at student deadline' => ['2026-09-05T16:00:00Z', CourseRegistrationPhase::STUDENT_OPEN],
            'advisor review begins' => ['2026-09-05T16:00:01Z', CourseRegistrationPhase::ADVISOR_REVIEW],
            'at advisor deadline' => ['2026-09-07T16:00:00Z', CourseRegistrationPhase::ADVISOR_REVIEW],
            'after advisor deadline' => ['2026-09-07T16:00:01Z', CourseRegistrationPhase::CLOSED],
        ];
    }

    public function test_legacy_null_deadlines_fall_back_to_ends_at_without_rewriting_history(): void
    {
        $versionId = $this->registrationWindow(
            startsAt: '2026-09-01 00:00:00',
            studentEndsAt: null,
            advisorEndsAt: null,
            endsAt: '2026-09-05 23:59:59',
        );

        $result = app(AcademicCalendarPolicyService::class)->courseRegistrationDeadlines(1, 1, CarbonImmutable::parse('2026-09-05T23:59:59Z'));

        self::assertSame(CourseRegistrationPhase::STUDENT_OPEN, $result->phase);
        self::assertTrue($result->legacyDeadlineFallback);
        self::assertTrue($result->studentRegistrationEndsAt?->equalTo($result->advisorApprovalEndsAt));
        self::assertNull(DB::table('academic_calendar_event_versions')->where('academic_calendar_event_version_id', $versionId)->value('student_registration_ends_at'));
    }

    public function test_phase6_replacement_window_rejects_legacy_and_partial_deadline_fallbacks(): void
    {
        $this->replacementWindow(null, null, '2026-09-08 00:00:00');
        $missing = app(AcademicCalendarPolicyService::class)->courseRegistrationReplacementDeadlines(1, 1);
        self::assertSame(CourseRegistrationPhase::CONFIGURATION_ERROR, $missing->phase);
        self::assertSame('course_registration_replacement_deadlines_missing', $missing->reasonCode);
        self::assertFalse($missing->legacyDeadlineFallback);

        DB::table('academic_calendar_event_versions')->where('academic_calendar_event_id', 2)->update([
            'student_registration_ends_at' => '2026-09-05 00:00:00',
        ]);
        $partial = app(AcademicCalendarPolicyService::class)->courseRegistrationReplacementDeadlines(1, 1);
        self::assertSame(CourseRegistrationPhase::CONFIGURATION_ERROR, $partial->phase);
        self::assertSame('course_registration_deadlines_incomplete', $partial->reasonCode);
    }

    public function test_phase6_replacement_window_uses_the_same_inclusive_student_and_advisor_boundaries(): void
    {
        $this->replacementWindow('2026-09-05 00:00:00', '2026-09-08 00:00:00');
        $policy = app(AcademicCalendarPolicyService::class);
        self::assertSame(CourseRegistrationPhase::STUDENT_OPEN, $policy->courseRegistrationReplacementDeadlines(1, 1, CarbonImmutable::parse('2026-09-05T00:00:00Z'))->phase);
        self::assertSame(CourseRegistrationPhase::ADVISOR_REVIEW, $policy->courseRegistrationReplacementDeadlines(1, 1, CarbonImmutable::parse('2026-09-08T00:00:00Z'))->phase);
        self::assertSame(CourseRegistrationPhase::CLOSED, $policy->courseRegistrationReplacementDeadlines(1, 1, CarbonImmutable::parse('2026-09-08T00:00:01Z'))->phase);
    }

    public function test_missing_phase_two_deadline_columns_return_controlled_configuration_error(): void
    {
        Schema::table('academic_calendar_event_versions', function (Blueprint $table): void {
            $table->dropColumn(['student_registration_ends_at', 'advisor_approval_ends_at']);
        });
        Schema::table('student_registration_requests', function (Blueprint $table): void {
            $table->dropColumn('expired_at');
        });

        $result = app(AcademicCalendarPolicyService::class)->courseRegistrationDeadlines(1, 1);

        self::assertSame(CourseRegistrationPhase::CONFIGURATION_ERROR, $result->phase);
        self::assertSame('course_registration_deadline_schema_not_ready', $result->reasonCode);
        self::assertSame(0, DB::table('academic_calendar_events')->count());
    }

    /** @dataProvider invalidWindowMutations */
    public function test_missing_ambiguous_cancelled_or_invalid_registration_configuration_fails_closed(callable $mutate, string $reason): void
    {
        $this->registrationWindow();
        $mutate();

        $result = app(AcademicCalendarPolicyService::class)->courseRegistrationDeadlines(1, 1, CarbonImmutable::parse('2026-09-03T12:00:00Z'));

        self::assertSame(CourseRegistrationPhase::CONFIGURATION_ERROR, $result->phase);
        self::assertSame($reason, $result->reasonCode);
    }

    public static function invalidWindowMutations(): array
    {
        return [
            'missing event' => [fn () => DB::table('academic_calendar_events')->delete(), 'course_registration_window_missing'],
            'ambiguous roots' => [function (): void {
                DB::table('academic_calendar_events')->insert(['academic_calendar_event_id' => 2, 'academic_year_id' => 1, 'semester_id' => 1, 'academic_calendar_event_type_id' => 1]);
            }, 'course_registration_window_ambiguous'],
            'cancelled event' => [fn () => DB::table('academic_calendar_events')->update(['cancelled_at' => '2026-09-02 00:00:00']), 'course_registration_window_missing'],
            'partial deadlines' => [fn () => DB::table('academic_calendar_event_versions')->update(['advisor_approval_ends_at' => null]), 'course_registration_deadlines_incomplete'],
            'invalid ordering' => [fn () => DB::table('academic_calendar_event_versions')->update(['student_registration_ends_at' => '2026-09-08 00:00:00']), 'course_registration_deadlines_invalid'],
            'not enforcement' => [fn () => DB::table('academic_calendar_event_versions')->update(['is_enforcement' => 0]), 'course_registration_window_not_enforcement'],
            'draft only' => [fn () => DB::table('academic_calendar_event_versions')->update(['publication_status' => 'draft']), 'course_registration_published_version_missing'],
        ];
    }

    public function test_current_replacement_changes_evaluation_without_mutating_superseded_deadlines(): void
    {
        $oldId = $this->registrationWindow(studentEndsAt: '2026-09-03 00:00:00', advisorEndsAt: '2026-09-04 00:00:00');
        DB::table('academic_calendar_event_versions')->where('academic_calendar_event_version_id', $oldId)->update([
            'publication_status' => 'superseded',
            'superseded_at' => '2026-09-02 00:00:00',
        ]);
        $this->version(1, 2, 'published', '2026-09-06 00:00:00', '2026-09-08 00:00:00');

        $result = app(AcademicCalendarPolicyService::class)->courseRegistrationDeadlines(1, 1, CarbonImmutable::parse('2026-09-05T12:00:00Z'));

        self::assertSame(CourseRegistrationPhase::STUDENT_OPEN, $result->phase);
        self::assertSame('2026-09-03 00:00:00', DB::table('academic_calendar_event_versions')->where('academic_calendar_event_version_id', $oldId)->value('student_registration_ends_at'));
    }

    public function test_calendar_write_semantics_require_explicit_ordered_term_deadlines_and_derive_generic_end(): void
    {
        $type = new AcademicCalendarEventType(['event_type_code' => 'course_registration']);
        $method = new ReflectionMethod(AcademicCalendarService::class, 'enforceRegistrationDeadlineSemantics');
        $service = new AcademicCalendarService;

        $payload = $method->invoke($service, $type, 1, [
            'starts_at' => '2026-09-01 08:00:00',
            'student_registration_ends_at' => '2026-09-05 16:00:00',
            'advisor_approval_ends_at' => '2026-09-07 16:00:00',
            'ends_at' => '2099-01-01 00:00:00',
            'is_enforcement' => false,
        ], null, true);

        self::assertTrue($payload['is_enforcement']);
        self::assertTrue($payload['ends_at']->equalTo($payload['advisor_approval_ends_at']));

        $this->expectException(AcademicCalendarException::class);
        $method->invoke($service, $type, 1, ['starts_at' => '2026-09-01 08:00:00'], null, true);
    }

    public function test_non_registration_events_keep_generic_dates_and_reject_specialized_metadata(): void
    {
        $type = new AcademicCalendarEventType(['event_type_code' => 'holiday']);
        $method = new ReflectionMethod(AcademicCalendarService::class, 'enforceRegistrationDeadlineSemantics');
        $service = new AcademicCalendarService;
        $generic = $method->invoke($service, $type, null, [
            'starts_at' => '2026-09-01 08:00:00',
            'ends_at' => '2026-09-02 08:00:00',
            'is_enforcement' => false,
        ]);
        self::assertSame('2026-09-02 08:00:00', $generic['ends_at']);

        $this->expectException(AcademicCalendarException::class);
        $method->invoke($service, $type, null, [
            'student_registration_ends_at' => '2026-09-02 08:00:00',
        ]);
    }

    public function test_advisor_decision_accepts_on_time_submission_during_both_open_phases_and_rejects_late_or_closed(): void
    {
        $service = $this->requestService();
        $method = new ReflectionMethod($service, 'assertAdvisorDecisionAllowed');
        $request = new StudentRegistrationRequest;
        $request->last_submitted_at = CarbonImmutable::parse('2026-09-05T00:00:00Z');

        foreach ([CourseRegistrationPhase::STUDENT_OPEN, CourseRegistrationPhase::ADVISOR_REVIEW] as $phase) {
            $deadline = new CourseRegistrationDeadlineResult(
                $phase,
                1,
                1,
                CarbonImmutable::parse('2026-09-05T00:00:00Z'),
                CarbonImmutable::parse('2026-09-01T00:00:00Z'),
                CarbonImmutable::parse('2026-09-05T00:00:00Z'),
                CarbonImmutable::parse('2026-09-07T00:00:00Z'),
            );
            $method->invoke($service, $request, $deadline);
        }

        $request->last_submitted_at = CarbonImmutable::parse('2026-09-05T00:00:01Z');
        try {
            $method->invoke($service, $request, new CourseRegistrationDeadlineResult(
                CourseRegistrationPhase::ADVISOR_REVIEW,
                1,
                1,
                CarbonImmutable::parse('2026-09-06T00:00:00Z'),
                CarbonImmutable::parse('2026-09-01T00:00:00Z'),
                CarbonImmutable::parse('2026-09-05T00:00:00Z'),
                CarbonImmutable::parse('2026-09-07T00:00:00Z'),
            ));
            self::fail('Late historical submission must fail closed.');
        } catch (RegistrationRequestException $exception) {
            self::assertSame(RegistrationRequestException::SUBMISSION_OUTSIDE_STUDENT_DEADLINE, $exception->errorCode);
        }

        $request->last_submitted_at = CarbonImmutable::parse('2026-09-05T00:00:00Z');
        try {
            $method->invoke($service, $request, $this->closedDeadline());
            self::fail('Advisor decisions after the deadline must fail closed.');
        } catch (RegistrationRequestException $exception) {
            self::assertSame(RegistrationRequestException::ADVISOR_DEADLINE_CLOSED, $exception->errorCode);
        }
    }

    /** @dataProvider unresolvedStatuses */
    public function test_overdue_unresolved_requests_expire_once_without_official_registration(string $status): void
    {
        DB::table('student_registration_requests')->insert([
            'student_registration_request_id' => 1,
            'student_id' => 1,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'status' => $status,
            'submission_version' => $status === 'draft' ? 0 : 1,
            'advisor_notes' => 'تبقى هذه الملاحظة محفوظة',
        ]);
        $service = $this->requestService();
        $deadline = $this->closedDeadline();
        $method = new ReflectionMethod($service, 'expireLockedIfDeadlineClosed');

        DB::transaction(function () use ($service, $deadline, $method): void {
            $request = StudentRegistrationRequest::query()->lockForUpdate()->findOrFail(1);
            self::assertTrue($method->invoke($service, $request, $deadline));
        });
        DB::transaction(function () use ($service, $deadline, $method): void {
            $request = StudentRegistrationRequest::query()->lockForUpdate()->findOrFail(1);
            self::assertTrue($method->invoke($service, $request, $deadline));
        });

        self::assertSame('expired', DB::table('student_registration_requests')->where('student_registration_request_id', 1)->value('status'));
        self::assertSame('تبقى هذه الملاحظة محفوظة', DB::table('student_registration_requests')->where('student_registration_request_id', 1)->value('advisor_notes'));
        self::assertSame(1, DB::table('student_registration_request_events')->where('event_type', 'expired_deadline')->count());
        self::assertNull(DB::table('student_registration_request_events')->where('event_type', 'expired_deadline')->value('actor_user_id'));
        self::assertSame(0, DB::table('student_course_registrations')->count());
    }

    public static function unresolvedStatuses(): array
    {
        return [['draft'], ['submitted'], ['returned']];
    }

    public function test_approved_request_never_expires(): void
    {
        DB::table('student_registration_requests')->insert([
            'student_registration_request_id' => 1,
            'student_id' => 1,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'status' => 'approved',
            'submission_version' => 1,
        ]);
        $service = $this->requestService();
        $method = new ReflectionMethod($service, 'expireLockedIfDeadlineClosed');

        DB::transaction(function () use ($service, $method): void {
            $request = StudentRegistrationRequest::query()->lockForUpdate()->findOrFail(1);
            self::assertFalse($method->invoke($service, $request, $this->closedDeadline()));
        });

        self::assertSame('approved', DB::table('student_registration_requests')->where('student_registration_request_id', 1)->value('status'));
        self::assertSame(0, DB::table('student_registration_request_events')->count());
    }

    public function test_student_materialization_is_blocked_after_student_cutoff_while_valid_advisor_request_materializes(): void
    {
        $this->registrationWindow(
            startsAt: '2026-09-01 00:00:00',
            studentEndsAt: '2026-09-05 00:00:00',
            advisorEndsAt: '2026-09-07 00:00:00',
        );
        $this->seedRegistrationRequestContext();
        $at = CarbonImmutable::parse('2026-09-06T00:00:00Z');
        CarbonImmutable::setTestNow($at);
        $registration = $this->realRegistrationService();

        try {
            DB::transaction(fn () => $registration->registerStudentWithinTransaction([
                'student_id' => 1,
                'course_offering_id' => 1,
            ], 7));
            self::fail('Direct materialization after the student cutoff must fail.');
        } catch (RegistrationException $exception) {
            self::assertSame(RegistrationException::COURSE_REGISTRATION_WINDOW_CLOSED, $exception->errorCode);
        }

        [$requests, $actor] = $this->advisorWorkflow($registration);
        $requests->approve($actor, StudentRegistrationRequest::query()->findOrFail(1));

        self::assertSame(1, DB::table('student_course_registrations')->count());
        self::assertSame('approved', DB::table('student_registration_requests')->where('student_registration_request_id', 1)->value('status'));
        self::assertNotNull(DB::table('student_registration_request_items')->where('student_registration_request_item_id', 1)->value('student_course_registration_id'));
    }

    public function test_full_advisor_path_one_second_after_deadline_expires_without_materialization(): void
    {
        $this->registrationWindow(
            startsAt: '2026-09-01 00:00:00',
            studentEndsAt: '2026-09-05 00:00:00',
            advisorEndsAt: '2026-09-07 00:00:00',
        );
        $this->seedRegistrationRequestContext();
        [$requests, $actor] = $this->advisorWorkflow($this->realRegistrationService());
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07T00:00:01Z'));

        try {
            $requests->approve($actor, StudentRegistrationRequest::query()->findOrFail(1));
            self::fail('Advisor approval after the deadline must fail.');
        } catch (RegistrationRequestException $exception) {
            self::assertSame(RegistrationRequestException::ADVISOR_DEADLINE_CLOSED, $exception->errorCode);
        }

        self::assertSame('expired', DB::table('student_registration_requests')->where('student_registration_request_id', 1)->value('status'));
        self::assertSame(0, DB::table('student_course_registrations')->count());
        self::assertSame(1, DB::table('student_registration_request_events')->where('event_type', 'expired_deadline')->count());
    }

    /** @dataProvider invalidAdvisorRequestStates */
    public function test_advisor_materialization_boundary_rejects_non_submitted_states(string $status): void
    {
        $this->registrationWindow();
        $this->seedRegistrationRequestContext($status);
        $service = $this->realRegistrationService();

        $this->expectRegistrationCode(
            fn () => DB::transaction(fn () => $service->materializeAdvisorApprovedRequestItemWithinTransaction(
                StudentRegistrationRequest::query()->findOrFail(1),
                StudentRegistrationRequestItem::query()->findOrFail(1),
                7,
                CarbonImmutable::parse('2026-09-06T00:00:00Z'),
            )),
            RegistrationException::LIVE_WORKFLOW_REQUIRED,
        );
        self::assertSame(0, DB::table('student_course_registrations')->count());
    }

    public static function invalidAdvisorRequestStates(): array
    {
        return [['draft'], ['returned'], ['expired'], ['approved']];
    }

    public function test_advisor_materialization_boundary_requires_transaction_request_item_and_matching_term(): void
    {
        $this->registrationWindow();
        $this->seedRegistrationRequestContext();
        $service = $this->realRegistrationService();
        $request = StudentRegistrationRequest::query()->findOrFail(1);
        $item = StudentRegistrationRequestItem::query()->findOrFail(1);

        $this->expectRegistrationCode(
            fn () => $service->materializeAdvisorApprovedRequestItemWithinTransaction($request, $item, 7),
            RegistrationException::LIVE_WORKFLOW_REQUIRED,
        );
        $this->expectRegistrationCode(
            fn () => DB::transaction(fn () => $service->materializeAdvisorApprovedRequestItemWithinTransaction(
                new StudentRegistrationRequest,
                new StudentRegistrationRequestItem,
                7,
            )),
            RegistrationException::LIVE_WORKFLOW_REQUIRED,
        );

        DB::table('student_registration_request_items')->where('student_registration_request_item_id', 1)->update(['student_registration_request_id' => 999]);
        $this->expectRegistrationCode(
            fn () => DB::transaction(fn () => $service->materializeAdvisorApprovedRequestItemWithinTransaction($request, $item->fresh(), 7)),
            RegistrationException::LIVE_WORKFLOW_REQUIRED,
        );

        DB::table('student_registration_request_items')->where('student_registration_request_item_id', 1)->update(['student_registration_request_id' => 1]);
        DB::table('course_offerings')->where('course_offering_id', 1)->update(['semester_id' => 2]);
        $this->expectRegistrationCode(
            fn () => DB::transaction(fn () => $service->materializeAdvisorApprovedRequestItemWithinTransaction($request, $item->fresh(), 7)),
            RegistrationException::LIVE_WORKFLOW_REQUIRED,
        );
        self::assertSame(0, DB::table('student_course_registrations')->count());
    }

    public function test_advisor_materialization_boundary_rechecks_submission_and_advisor_deadlines(): void
    {
        $this->registrationWindow(
            startsAt: '2026-09-01 00:00:00',
            studentEndsAt: '2026-09-05 00:00:00',
            advisorEndsAt: '2026-09-07 00:00:00',
        );
        $this->seedRegistrationRequestContext();
        $service = $this->realRegistrationService();
        $request = StudentRegistrationRequest::query()->findOrFail(1);
        $item = StudentRegistrationRequestItem::query()->findOrFail(1);

        DB::table('student_registration_requests')->where('student_registration_request_id', 1)->update(['last_submitted_at' => '2026-09-05 00:00:01']);
        $this->expectRegistrationCode(
            fn () => DB::transaction(fn () => $service->materializeAdvisorApprovedRequestItemWithinTransaction($request->fresh(), $item, 7, CarbonImmutable::parse('2026-09-06T00:00:00Z'))),
            RegistrationException::COURSE_REGISTRATION_WINDOW_CLOSED,
        );

        DB::table('student_registration_requests')->where('student_registration_request_id', 1)->update(['last_submitted_at' => '2026-09-05 00:00:00']);
        $this->expectRegistrationCode(
            fn () => DB::transaction(fn () => $service->materializeAdvisorApprovedRequestItemWithinTransaction($request->fresh(), $item, 7, CarbonImmutable::parse('2026-09-07T00:00:01Z'))),
            RegistrationException::COURSE_REGISTRATION_WINDOW_CLOSED,
        );
        self::assertSame(0, DB::table('student_course_registrations')->count());
    }

    public function test_phase3_below_twelve_hours_is_an_advisory_response_not_a_registration_failure(): void
    {
        $this->seedRegistrationRequestContext();
        $registration = Mockery::mock(RegistrationService::class);
        $registration->shouldReceive('hoursSnapshot')->once()->andReturn([
            'registered_hours' => 0,
            'official_cgpa' => 2.75,
            'max_allowed_hours' => 18,
            'remaining_hours' => 18,
            'recommended_minimum_hours' => 12,
            'official_passed_course_ids' => [],
        ]);
        $registration->shouldReceive('currentOfferingIds')->once()->andReturn([]);
        $service = new RegistrationRequestService(
            $registration,
            Mockery::mock(AcademicTermResolver::class),
            Mockery::mock(DataScopeService::class),
            Mockery::mock(AcademicRequirementService::class),
        );
        $hoursFor = new ReflectionMethod($service, 'hoursFor');

        $hours = $hoursFor->invoke(
            $service,
            \App\Models\Student::query()->findOrFail(1),
            1,
            1,
            StudentRegistrationRequest::query()->findOrFail(1),
        );

        self::assertSame(3, $hours['request_hours']);
        self::assertSame(12, $hours['recommended_minimum_hours']);
        self::assertTrue($hours['below_recommended_minimum']);
        self::assertSame(18, $hours['max_allowed_hours']);
    }

    public function test_phase3_recommended_minimum_uses_projected_term_hours_for_live_and_approved_snapshots(): void
    {
        $this->seedRegistrationRequestContext();
        $student = \App\Models\Student::query()->findOrFail(1);
        $request = StudentRegistrationRequest::query()->findOrFail(1);

        $liveRegistration = Mockery::mock(RegistrationService::class);
        $liveRegistration->shouldReceive('hoursSnapshot')->once()->andReturn([
            'registered_hours' => 9,
            'official_cgpa' => 2.75,
            'max_allowed_hours' => 18,
            'remaining_hours' => 9,
            'recommended_minimum_hours' => 12,
            'official_passed_course_ids' => [],
        ]);
        $liveRegistration->shouldReceive('currentOfferingIds')->once()->andReturn([]);
        $liveService = new RegistrationRequestService(
            $liveRegistration,
            Mockery::mock(AcademicTermResolver::class),
            Mockery::mock(DataScopeService::class),
            Mockery::mock(AcademicRequirementService::class),
        );
        $hoursFor = new ReflectionMethod($liveService, 'hoursFor');
        $live = $hoursFor->invoke($liveService, $student, 1, 1, $request);

        self::assertSame(12, $live['projected_hours']);
        self::assertFalse($live['below_recommended_minimum']);

        DB::table('student_registration_requests')->where('student_registration_request_id', 1)->update([
            'status' => 'approved',
            'registered_hours_before_approval' => 9,
            'request_hours_at_approval' => 3,
            'projected_hours_at_approval' => 12,
            'max_allowed_hours_at_approval' => 18,
            'remaining_hours_after_approval' => 6,
        ]);
        $approvedRegistration = Mockery::mock(RegistrationService::class);
        $approvedRegistration->shouldReceive('hoursSnapshot')->once()->andReturn([
            'registered_hours' => 12,
            'official_cgpa' => 2.75,
            'max_allowed_hours' => 18,
            'remaining_hours' => 6,
            'recommended_minimum_hours' => 12,
            'official_passed_course_ids' => [],
        ]);
        $approvedRegistration->shouldReceive('currentOfferingIds')->once()->andReturn([1]);
        $approvedService = new RegistrationRequestService(
            $approvedRegistration,
            Mockery::mock(AcademicTermResolver::class),
            Mockery::mock(DataScopeService::class),
            Mockery::mock(AcademicRequirementService::class),
        );
        $approvedHoursFor = new ReflectionMethod($approvedService, 'hoursFor');
        $approved = $approvedHoursFor->invoke(
            $approvedService,
            $student,
            1,
            1,
            StudentRegistrationRequest::query()->findOrFail(1),
        );

        self::assertSame('approved_snapshot', $approved['source']);
        self::assertFalse($approved['below_recommended_minimum']);
    }

    public function test_phase3_zero_legacy_seats_do_not_block_request_addition(): void
    {
        $this->registrationWindow();
        $this->seedRegistrationRequestContext('draft');
        DB::table('student_registration_request_items')->delete();
        DB::table('course_offerings')->where('course_offering_id', 1)->update(['available_seats' => 0]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03T00:00:00Z'));

        $registration = $this->realRegistrationService([
            'cumulative_gpa' => null,
            'official_completed_courses' => [],
        ]);
        $registration->shouldReceive('selfRegistrationOpenSemesters')->once()->andReturn(
            collect([\App\Models\Semester::query()->findOrFail(1)])
        );
        [$requests, $actor] = $this->advisorWorkflow($registration);

        $request = $requests->addItem(
            \App\Models\Student::query()->findOrFail(1),
            \App\Models\CourseOffering::query()->findOrFail(1),
            $actor,
        );

        self::assertSame('draft', $request->status);
        self::assertSame(1, DB::table('student_registration_request_items')->count());
        self::assertSame(0, (int) DB::table('course_offerings')->where('course_offering_id', 1)->value('available_seats'));
    }

    public function test_phase3_three_hour_request_can_be_submitted_below_the_recommended_twelve_hours(): void
    {
        $this->registrationWindow();
        $this->seedRegistrationRequestContext('draft');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03T00:00:00Z'));

        $registration = $this->realRegistrationService([
            'cumulative_gpa' => 2.75,
            'official_completed_courses' => [],
        ]);
        $registration->shouldReceive('selfRegistrationOpenSemesters')->twice()->andReturn(
            collect([\App\Models\Semester::query()->findOrFail(1)])
        );
        [$requests, $actor] = $this->advisorWorkflow($registration);

        $request = $requests->submit(
            \App\Models\Student::query()->findOrFail(1),
            $actor,
            1,
        );

        self::assertSame('submitted', $request->status);
        self::assertSame(1, (int) $request->submission_version);
        self::assertSame('submitted', DB::table('student_registration_request_events')->value('event_type'));
        self::assertSame(0, DB::table('student_course_registrations')->count());
    }

    public function test_phase3_six_hour_request_submits_with_warning_and_is_fully_approved(): void
    {
        $this->registrationWindow();
        $this->seedRegistrationRequestContext('draft');
        $this->addThreeHourRequestOfferings(2);

        $registration = $this->realRegistrationService([
            'cumulative_gpa' => 2.75,
            'official_completed_courses' => [],
        ]);
        $registration->shouldReceive('selfRegistrationOpenSemesters')->zeroOrMoreTimes()->andReturn(
            collect([\App\Models\Semester::query()->findOrFail(1)])
        );
        [$requests, $actor] = $this->advisorWorkflow($registration);
        $student = \App\Models\Student::query()->findOrFail(1);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03T00:00:00Z'));
        $submitted = $requests->submit($student, $actor, 1);
        $studentView = $requests->studentRequestView($student, $submitted);

        self::assertSame('submitted', $submitted->status);
        self::assertSame(6, $studentView['hours']['request_hours']);
        self::assertSame(6, $studentView['hours']['projected_hours']);
        self::assertSame(12, $studentView['hours']['recommended_minimum_hours']);
        self::assertTrue($studentView['hours']['below_recommended_minimum']);
        self::assertSame(0, DB::table('student_course_registrations')->count());

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06T00:00:00Z'));
        $approved = $requests->approve(
            $actor,
            StudentRegistrationRequest::query()->findOrFail($submitted->student_registration_request_id),
        );

        $stored = DB::table('student_registration_requests')
            ->where('student_registration_request_id', $submitted->student_registration_request_id)
            ->first();
        self::assertSame('approved', $approved['status']);
        self::assertSame('approved', $stored->status);
        self::assertSame(6, (int) $stored->request_hours_at_approval);
        self::assertSame(6, (int) $stored->projected_hours_at_approval);
        self::assertSame(18, (int) $stored->max_allowed_hours_at_approval);
        self::assertTrue($approved['hours']['below_recommended_minimum']);
        self::assertSame(2, DB::table('student_course_registrations')->count());
        self::assertSame(2, collect($approved['finalized_registrations'])->count());
    }

    public function test_phase3_advisor_approval_recomputes_current_cgpa_and_rolls_back_an_over_limit_request(): void
    {
        $this->registrationWindow();
        $this->seedRegistrationRequestContext();
        $this->addThreeHourRequestOfferings(7);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06T00:00:00Z'));
        [$requests, $actor] = $this->advisorWorkflow($this->realRegistrationService([
            'cumulative_gpa' => 2.99,
            'official_completed_courses' => [],
        ]));

        try {
            $requests->approve($actor, StudentRegistrationRequest::query()->findOrFail(1));
            self::fail('A request submitted under an earlier higher cap must use the current official CGPA at approval.');
        } catch (RegistrationRequestException $exception) {
            self::assertSame('registration_request_approval_failed', $exception->errorCode);
        }

        self::assertSame('submitted', DB::table('student_registration_requests')->where('student_registration_request_id', 1)->value('status'));
        self::assertNull(DB::table('student_registration_requests')->where('student_registration_request_id', 1)->value('max_allowed_hours_at_approval'));
        self::assertSame(0, DB::table('student_course_registrations')->count());
        self::assertSame(2, (int) DB::table('course_offerings')->where('course_offering_id', 1)->value('available_seats'));
    }

    public function test_phase3_approval_snapshot_persists_the_current_twenty_one_hour_policy_without_seat_mutation(): void
    {
        $this->registrationWindow();
        $this->seedRegistrationRequestContext();
        $this->addThreeHourRequestOfferings(7);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06T00:00:00Z'));
        [$requests, $actor] = $this->advisorWorkflow($this->realRegistrationService([
            'cumulative_gpa' => 3.0,
            'official_completed_courses' => [],
        ]));

        $requests->approve($actor, StudentRegistrationRequest::query()->findOrFail(1));

        $request = DB::table('student_registration_requests')->where('student_registration_request_id', 1)->first();
        self::assertSame('approved', $request->status);
        self::assertSame(21, (int) $request->request_hours_at_approval);
        self::assertSame(21, (int) $request->projected_hours_at_approval);
        self::assertSame(21, (int) $request->max_allowed_hours_at_approval);
        self::assertSame(0, (int) $request->remaining_hours_after_approval);
        self::assertSame(7, DB::table('student_course_registrations')->count());
        self::assertSame(2, (int) DB::table('course_offerings')->where('course_offering_id', 1)->value('available_seats'));
    }

    public function test_phase4_complete_timetable_allows_add_but_incomplete_target_fails(): void
    {
        $this->registrationWindow();
        $this->seedRegistrationRequestContext('draft');
        DB::table('student_registration_request_items')->delete();
        $this->insertSchedule(1, '08:00:00', '09:00:00');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03T00:00:00Z'));
        [$requests, $actor] = $this->realTimetableWorkflow();
        $student = Student::query()->findOrFail(1);

        $added = $requests->addItem($student, CourseOffering::query()->findOrFail(1), $actor);
        self::assertSame([1], $added->items->pluck('course_offering_id')->map(fn ($id): int => (int) $id)->all());

        DB::table('student_registration_request_items')->delete();
        DB::table('course_offering_schedule_slots')->delete();
        try {
            $requests->addItem($student, CourseOffering::query()->findOrFail(1), $actor);
            self::fail('An incomplete target timetable must block addItem.');
        } catch (RegistrationRequestException $exception) {
            self::assertSame(RegistrationException::OFFERING_SCHEDULE_INCOMPLETE, $exception->errorCode);
            self::assertSame(0, DB::table('student_registration_request_items')->count());
        }
    }

    public function test_phase4_add_item_distinguishes_conflict_reference_completeness_and_term_scope(): void
    {
        $this->registrationWindow();
        $this->seedRegistrationRequestContext('draft');
        DB::table('student_registration_request_items')->delete();
        $this->addComparisonOffering(2);
        $this->insertSchedule(1, '08:00:00', '09:00:00');
        $this->insertSchedule(2, '08:30:00', '09:30:00');
        DB::table('student_course_registrations')->insert([
            'student_id' => 1,
            'course_offering_id' => 2,
            'registration_status_id' => 1,
        ]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03T00:00:00Z'));
        [$requests, $actor] = $this->realTimetableWorkflow();
        $student = Student::query()->findOrFail(1);

        try {
            $requests->addItem($student, CourseOffering::query()->findOrFail(1), $actor);
            self::fail('An overlapping current registration must block addItem.');
        } catch (RegistrationRequestException $exception) {
            self::assertSame(RegistrationException::TIMETABLE_CONFLICT, $exception->errorCode);
            self::assertSame(2, $exception->itemFailures[0]['conflicts'][0]['conflicting_with']['course_offering_id']);
        }

        DB::table('student_course_registrations')->update(['registration_status_id' => 2]);
        $added = $requests->addItem($student, CourseOffering::query()->findOrFail(1), $actor);
        self::assertSame(1, $added->items->count(), 'Dropped registrations must not conflict.');

        DB::table('student_registration_request_items')->delete();
        DB::table('student_course_registrations')->update(['registration_status_id' => 3]);
        $added = $requests->addItem($student, CourseOffering::query()->findOrFail(1), $actor);
        self::assertSame(1, $added->items->count(), 'Withdrawn registrations must not conflict.');

        DB::table('student_registration_request_items')->delete();
        DB::table('student_course_registrations')->update(['registration_status_id' => 1]);
        DB::table('course_offerings')->where('course_offering_id', 2)->update(['semester_id' => 2]);
        $added = $requests->addItem($student, CourseOffering::query()->findOrFail(1), $actor);
        self::assertSame(1, $added->items->count(), 'Another actual term must not conflict.');

        DB::table('student_registration_request_items')->delete();
        DB::table('course_offerings')->where('course_offering_id', 2)->update(['semester_id' => 1]);
        DB::table('course_offering_schedule_slots')->where('course_offering_id', 2)->delete();
        try {
            $requests->addItem($student, CourseOffering::query()->findOrFail(1), $actor);
            self::fail('An incomplete same-term official source must fail closed.');
        } catch (RegistrationRequestException $exception) {
            self::assertSame(RegistrationException::TIMETABLE_REFERENCE_INCOMPLETE, $exception->errorCode);
            self::assertSame(2, $exception->itemFailures[0]['incomplete_timetable_sources'][0]['course_offering_id']);
        }
    }

    public function test_phase4_request_items_use_half_open_intervals_and_submit_rechecks_the_whole_request(): void
    {
        $this->registrationWindow();
        $this->seedRegistrationRequestContext('draft');
        $this->addComparisonOffering(2);
        DB::table('student_registration_request_items')->insert([
            'student_registration_request_item_id' => 2,
            'student_registration_request_id' => 1,
            'course_offering_id' => 2,
        ]);
        $this->insertSchedule(1, '08:00:00', '09:00:00');
        $this->insertSchedule(2, '08:30:00', '09:30:00');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03T00:00:00Z'));
        [$requests, $actor] = $this->realTimetableWorkflow();
        $student = Student::query()->findOrFail(1);

        DB::table('student_registration_request_items')->where('course_offering_id', 1)->delete();
        try {
            $requests->addItem($student, CourseOffering::query()->findOrFail(1), $actor);
            self::fail('An overlapping current request item must block addItem.');
        } catch (RegistrationRequestException $exception) {
            self::assertSame(RegistrationException::TIMETABLE_CONFLICT, $exception->errorCode);
        }

        DB::table('course_offering_schedule_slots')->where('course_offering_id', 2)->update([
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);
        $requests->addItem($student, CourseOffering::query()->findOrFail(1), $actor);
        $submitted = $requests->submit($student, $actor, 1);
        self::assertSame('submitted', $submitted->status, 'Adjacent intervals must be allowed.');

        DB::table('student_registration_requests')->where('student_registration_request_id', 1)->update([
            'status' => 'draft',
            'submission_version' => 0,
            'first_submitted_at' => null,
            'last_submitted_at' => null,
        ]);
        DB::table('course_offering_schedule_slots')->where('course_offering_id', 2)->update(['start_time' => '08:30:00']);
        try {
            $requests->submit($student, $actor, 1);
            self::fail('A request containing mutually conflicting items must not submit.');
        } catch (RegistrationRequestException $exception) {
            self::assertSame('registration_request_invalid', $exception->errorCode);
            self::assertSame('draft', DB::table('student_registration_requests')->where('student_registration_request_id', 1)->value('status'));
        }
    }

    public function test_phase4_advisor_approval_revalidates_conflicts_atomically_then_materializes_valid_request_after_cutoff(): void
    {
        $this->registrationWindow();
        $this->seedRegistrationRequestContext();
        $this->addComparisonOffering(2);
        DB::table('student_registration_request_items')->insert([
            'student_registration_request_item_id' => 2,
            'student_registration_request_id' => 1,
            'course_offering_id' => 2,
        ]);
        $this->insertSchedule(1, '08:00:00', '09:00:00');
        $this->insertSchedule(2, '08:30:00', '09:30:00');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06T00:00:00Z'));
        [$requests, $actor] = $this->realTimetableWorkflow();

        try {
            $requests->approve($actor, StudentRegistrationRequest::query()->findOrFail(1));
            self::fail('Advisor approval must revalidate the complete timetable.');
        } catch (RegistrationRequestException $exception) {
            self::assertSame('registration_request_approval_failed', $exception->errorCode);
            self::assertSame(0, DB::table('student_course_registrations')->count());
            self::assertSame('submitted', DB::table('student_registration_requests')->where('student_registration_request_id', 1)->value('status'));
        }

        DB::table('course_offering_schedule_slots')->where('course_offering_id', 2)->update([
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);
        $approved = $requests->approve($actor, StudentRegistrationRequest::query()->findOrFail(1));
        self::assertSame('approved', $approved['status']);
        self::assertSame(2, DB::table('student_course_registrations')->count());
    }

    public function test_phase4_final_materialization_service_cannot_bypass_an_official_timetable_conflict(): void
    {
        $this->registrationWindow();
        $this->seedRegistrationRequestContext();
        $this->addComparisonOffering(2);
        $this->insertSchedule(1, '08:00:00', '09:00:00');
        $this->insertSchedule(2, '08:30:00', '09:30:00');
        DB::table('student_course_registrations')->insert([
            'student_id' => 1,
            'course_offering_id' => 2,
            'registration_status_id' => 1,
        ]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06T00:00:00Z'));
        $registration = $this->realRegistrationService([
            'cumulative_gpa' => 2.75,
            'official_completed_courses' => [],
        ], $this->realScheduleService());

        try {
            DB::transaction(fn () => $registration->materializeAdvisorApprovedRequestItemWithinTransaction(
                StudentRegistrationRequest::query()->findOrFail(1),
                StudentRegistrationRequestItem::query()->findOrFail(1),
                7,
                CarbonImmutable::parse('2026-09-06T00:00:00Z'),
            ));
            self::fail('The final materialization boundary must re-evaluate official timetable conflicts.');
        } catch (RegistrationException $exception) {
            self::assertSame(RegistrationException::TIMETABLE_CONFLICT, $exception->errorCode);
            self::assertFalse(DB::table('student_course_registrations')->where('course_offering_id', 1)->exists());
            self::assertSame(1, DB::table('student_course_registrations')->count());
        }
    }

    public function test_phase4_trusted_materialization_cannot_bypass_a_conflicting_request_peer(): void
    {
        $this->registrationWindow();
        $this->seedRegistrationRequestContext();
        $this->addComparisonOffering(2);
        DB::table('student_registration_request_items')->insert([
            'student_registration_request_item_id' => 2,
            'student_registration_request_id' => 1,
            'course_offering_id' => 2,
        ]);
        $this->insertSchedule(1, '08:00:00', '09:00:00');
        $this->insertSchedule(2, '08:30:00', '09:30:00');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06T00:00:00Z'));
        $registration = $this->realRegistrationService([
            'cumulative_gpa' => 2.75,
            'official_completed_courses' => [],
        ], $this->realScheduleService());

        try {
            DB::transaction(fn () => $registration->materializeAdvisorApprovedRequestItemWithinTransaction(
                StudentRegistrationRequest::query()->findOrFail(1),
                StudentRegistrationRequestItem::query()->findOrFail(1),
                7,
                CarbonImmutable::parse('2026-09-06T00:00:00Z'),
            ));
            self::fail('The trusted boundary must include other current request items.');
        } catch (RegistrationException $exception) {
            self::assertSame(RegistrationException::TIMETABLE_CONFLICT, $exception->errorCode);
            self::assertSame(0, DB::table('student_course_registrations')->count());
        }
    }

    public function test_phase4_trusted_materialization_fails_closed_for_an_incomplete_request_peer(): void
    {
        $this->registrationWindow();
        $this->seedRegistrationRequestContext();
        $this->addComparisonOffering(2);
        DB::table('student_registration_request_items')->insert([
            'student_registration_request_item_id' => 2,
            'student_registration_request_id' => 1,
            'course_offering_id' => 2,
        ]);
        $this->insertSchedule(1, '08:00:00', '09:00:00');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06T00:00:00Z'));
        $registration = $this->realRegistrationService([
            'cumulative_gpa' => 2.75,
            'official_completed_courses' => [],
        ], $this->realScheduleService());

        try {
            DB::transaction(fn () => $registration->materializeAdvisorApprovedRequestItemWithinTransaction(
                StudentRegistrationRequest::query()->findOrFail(1),
                StudentRegistrationRequestItem::query()->findOrFail(1),
                7,
                CarbonImmutable::parse('2026-09-06T00:00:00Z'),
            ));
            self::fail('The trusted boundary must fail closed for incomplete request peers.');
        } catch (RegistrationException $exception) {
            self::assertSame(RegistrationException::TIMETABLE_REFERENCE_INCOMPLETE, $exception->errorCode);
            self::assertSame(2, $exception->data['incomplete_timetable_sources'][0]['course_offering_id']);
            self::assertSame(0, DB::table('student_course_registrations')->count());
        }
    }

    private function requestService(): RegistrationRequestService
    {
        return new RegistrationRequestService(
            Mockery::mock(RegistrationService::class),
            Mockery::mock(AcademicTermResolver::class),
            Mockery::mock(DataScopeService::class),
            Mockery::mock(AcademicRequirementService::class),
        );
    }

    private function realRegistrationService(
        ?array $metrics = null,
        ?CourseOfferingScheduleService $schedules = null,
    ): RegistrationService
    {
        $requirements = Mockery::mock(AcademicRequirementService::class);
        $requirements->shouldReceive('assertRegistrationCandidateAllowed')->zeroOrMoreTimes();
        $requirements->shouldReceive('buildRegistrationCommitmentContext')->zeroOrMoreTimes()->andReturn([]);
        $requirements->shouldReceive('evaluateRegistrationCandidate')->zeroOrMoreTimes()->andReturn(['allowed' => true, 'reason' => null]);
        $requirements->shouldReceive('validateRegistrationRequestCommitment')->zeroOrMoreTimes()->andReturn([]);
        $grades = Mockery::mock(GradeService::class);
        $grades->shouldReceive('officialCumulativeMetrics')->zeroOrMoreTimes()->andReturn($metrics ?? [
            'cumulative_gpa' => null,
            'official_completed_courses' => [],
        ]);

        $service = Mockery::mock(RegistrationService::class, [
            $requirements,
            app(AcademicCalendarPolicyService::class),
            $grades,
            $schedules ?? $this->permissiveScheduleService(),
        ])->makePartial();
        $service->shouldReceive('assertSelfRegistrationAllowed')->zeroOrMoreTimes();
        $service->shouldReceive('getMissingPrerequisites')->zeroOrMoreTimes()->andReturn([]);

        return $service;
    }

    private function permissiveScheduleService(): CourseOfferingScheduleService
    {
        $service = Mockery::mock(CourseOfferingScheduleService::class);
        $service->shouldReceive('registrationEvaluations')->zeroOrMoreTimes()->andReturnUsing(
            fn ($student, $targets): array => $targets->mapWithKeys(fn ($offering): array => [
                (int) $offering->course_offering_id => [
                    'reason' => null,
                    'schedule' => ['schema_ready' => true, 'components_defined' => true, 'complete' => true, 'slots' => []],
                    'conflicts' => [],
                    'incomplete_timetable_sources' => [],
                ],
            ])->all(),
        );
        $service->shouldReceive('describeMany')->zeroOrMoreTimes()->andReturn([]);

        return $service;
    }

    private function realScheduleService(): CourseOfferingScheduleService
    {
        $teaching = Mockery::mock(TeachingAssignmentService::class);
        $coverage = new CourseOfferingInstructorCoverageService($teaching);

        return new CourseOfferingScheduleService(
            $coverage,
            app(AcademicCalendarPolicyService::class),
            Mockery::mock(DataScopeService::class),
            $teaching,
        );
    }

    /** @return array{RegistrationRequestService, User} */
    private function realTimetableWorkflow(): array
    {
        $registration = $this->realRegistrationService([
            'cumulative_gpa' => 2.75,
            'official_completed_courses' => [],
        ], $this->realScheduleService());
        $registration->shouldReceive('selfRegistrationOpenSemesters')->zeroOrMoreTimes()->andReturn(
            collect([\App\Models\Semester::query()->findOrFail(1)]),
        );

        return $this->advisorWorkflow($registration);
    }

    private function addComparisonOffering(int $id, int $semesterId = 1): void
    {
        DB::table('courses')->insert([
            'course_id' => $id,
            'course_code' => 'C'.$id,
            'course_name' => 'Course '.$id,
            'credit_hours' => 3,
            'theoretical_hours' => 2,
            'practical_hours' => 0,
        ]);
        DB::table('course_offerings')->insert([
            'course_offering_id' => $id,
            'course_id' => $id,
            'academic_year_id' => 1,
            'semester_id' => $semesterId,
            'capacity' => 10,
            'available_seats' => 10,
            'status' => 'open',
        ]);
    }

    private function insertSchedule(int $offeringId, string $start, string $end): void
    {
        DB::table('course_offering_schedule_slots')->insert([
            'course_offering_id' => $offeringId,
            'component_type' => 'theoretical',
            'day_of_week' => 1,
            'start_time' => $start,
            'end_time' => $end,
            'created_by_user_id' => 7,
            'created_at' => '2026-09-01 00:00:00',
            'updated_at' => '2026-09-01 00:00:00',
        ]);
    }

    /** @return array{RegistrationRequestService, User} */
    private function advisorWorkflow(RegistrationService $registration): array
    {
        $terms = Mockery::mock(AcademicTermResolver::class);
        $terms->shouldReceive('uniqueCurrentAcademicYear')->zeroOrMoreTimes()->andReturn(AcademicYear::query()->findOrFail(1));
        $scopes = Mockery::mock(DataScopeService::class);
        $scopes->shouldReceive('canStaffAccessStudent')->zeroOrMoreTimes()->andReturn(true);
        $requirements = Mockery::mock(AcademicRequirementService::class);
        $requirements->shouldReceive('buildRegistrationCommitmentContext')->zeroOrMoreTimes()->andReturn([]);
        $requirements->shouldReceive('evaluateRegistrationCandidate')->zeroOrMoreTimes()->andReturn(['allowed' => true, 'reason' => null]);
        $requirements->shouldReceive('validateRegistrationRequestCommitment')->zeroOrMoreTimes()->andReturn([]);

        $actor = Mockery::mock(User::class)->makePartial();
        $actor->setAttribute('user_id', 7);
        $actor->exists = true;
        $actor->shouldReceive('hasPermission')->with('registration_requests.review')->andReturn(true);

        return [new RegistrationRequestService($registration, $terms, $scopes, $requirements), $actor];
    }

    private function seedRegistrationRequestContext(string $status = 'submitted'): void
    {
        DB::table('users')->insert(['user_id' => 7, 'username' => 'advisor']);
        DB::table('courses')->insert(['course_id' => 1, 'course_code' => 'C101', 'course_name' => 'Course 101', 'credit_hours' => 3]);
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
        DB::table('student_registration_requests')->insert([
            'student_registration_request_id' => 1,
            'student_id' => 1,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'status' => $status,
            'submission_version' => $status === 'draft' ? 0 : 1,
            'first_submitted_at' => $status === 'draft' ? null : '2026-09-05 00:00:00',
            'last_submitted_at' => $status === 'draft' ? null : '2026-09-05 00:00:00',
            'expired_at' => $status === 'expired' ? '2026-09-08 00:00:00' : null,
            'approved_at' => $status === 'approved' ? '2026-09-06 00:00:00' : null,
        ]);
        DB::table('student_registration_request_items')->insert([
            'student_registration_request_item_id' => 1,
            'student_registration_request_id' => 1,
            'course_offering_id' => 1,
        ]);
    }

    private function addThreeHourRequestOfferings(int $throughOfferingId): void
    {
        foreach (range(2, $throughOfferingId) as $id) {
            DB::table('courses')->insert([
                'course_id' => $id,
                'course_code' => 'C'.$id,
                'course_name' => 'Course '.$id,
                'credit_hours' => 3,
            ]);
            DB::table('course_offerings')->insert([
                'course_offering_id' => $id,
                'course_id' => $id,
                'academic_year_id' => 1,
                'semester_id' => 1,
                'capacity' => 0,
                'available_seats' => 0,
                'status' => 'open',
            ]);
            DB::table('student_registration_request_items')->insert([
                'student_registration_request_item_id' => $id,
                'student_registration_request_id' => 1,
                'course_offering_id' => $id,
            ]);
        }
    }

    private function expectRegistrationCode(callable $operation, string $errorCode): void
    {
        try {
            $operation();
            self::fail('Expected registration failure '.$errorCode);
        } catch (RegistrationException $exception) {
            self::assertSame($errorCode, $exception->errorCode);
        }
    }

    private function closedDeadline(): CourseRegistrationDeadlineResult
    {
        return new CourseRegistrationDeadlineResult(
            CourseRegistrationPhase::CLOSED,
            1,
            1,
            CarbonImmutable::parse('2026-09-08T00:00:01Z'),
            CarbonImmutable::parse('2026-09-01T00:00:00Z'),
            CarbonImmutable::parse('2026-09-05T00:00:00Z'),
            CarbonImmutable::parse('2026-09-08T00:00:00Z'),
        );
    }

    private function registrationWindow(
        string $startsAt = '2026-09-01 00:00:00',
        ?string $studentEndsAt = '2026-09-05 00:00:00',
        ?string $advisorEndsAt = '2026-09-08 00:00:00',
        ?string $endsAt = null,
    ): int {
        DB::table('academic_calendar_events')->insert([
            'academic_calendar_event_id' => 1,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'academic_calendar_event_type_id' => 1,
        ]);

        return $this->version(1, 1, 'published', $studentEndsAt, $advisorEndsAt, $startsAt, $endsAt);
    }

    private function replacementWindow(?string $studentEndsAt, ?string $advisorEndsAt, ?string $endsAt = null): int
    {
        DB::table('academic_calendar_event_types')->insert(['academic_calendar_event_type_id' => 2, 'event_type_code' => 'course_registration_replacement', 'is_active' => 1]);
        DB::table('academic_calendar_events')->insert(['academic_calendar_event_id' => 2, 'academic_year_id' => 1, 'semester_id' => 1, 'academic_calendar_event_type_id' => 2]);

        return $this->version(2, 1, 'published', $studentEndsAt, $advisorEndsAt, '2026-09-01 00:00:00', $endsAt);
    }

    private function version(
        int $eventId,
        int $versionNumber,
        string $status,
        ?string $studentEndsAt,
        ?string $advisorEndsAt,
        string $startsAt = '2026-09-01 00:00:00',
        ?string $endsAt = null,
    ): int {
        return DB::table('academic_calendar_event_versions')->insertGetId([
            'academic_calendar_event_id' => $eventId,
            'version_number' => $versionNumber,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt ?? $advisorEndsAt ?? '2026-09-05 00:00:00',
            'student_registration_ends_at' => $studentEndsAt,
            'advisor_approval_ends_at' => $advisorEndsAt,
            'is_enforcement' => 1,
            'publication_status' => $status,
            'superseded_at' => $status === 'superseded' ? '2026-09-02 00:00:00' : null,
        ]);
    }

    private function seedOperationalContext(): void
    {
        DB::table('academic_years')->insert(['academic_year_id' => 1, 'is_current' => 1, 'is_active' => 1, 'calendar_lifecycle_status' => 'active']);
        DB::table('semesters')->insert(['semester_id' => 1, 'semester_code' => 'first', 'is_active' => 1]);
        DB::table('academic_calendar_event_types')->insert(['academic_calendar_event_type_id' => 1, 'event_type_code' => 'course_registration', 'is_active' => 1]);
        DB::table('students')->insert(['student_id' => 1]);
    }

    private function schema(): void
    {
        Schema::create('academic_years', function (Blueprint $table): void {
            $table->increments('academic_year_id');
            $table->string('year_name')->nullable();
            $table->boolean('is_current');
            $table->boolean('is_active');
            $table->string('calendar_lifecycle_status');
        });
        Schema::create('semesters', function (Blueprint $table): void {
            $table->increments('semester_id');
            $table->string('semester_code');
            $table->string('semester_name')->nullable();
            $table->boolean('is_active');
        });
        Schema::create('academic_calendar_event_types', function (Blueprint $table): void {
            $table->increments('academic_calendar_event_type_id');
            $table->string('event_type_code');
            $table->boolean('is_active');
        });
        Schema::create('academic_calendar_events', function (Blueprint $table): void {
            $table->increments('academic_calendar_event_id');
            $table->integer('academic_year_id');
            $table->integer('semester_id')->nullable();
            $table->integer('academic_calendar_event_type_id');
            $table->dateTime('cancelled_at')->nullable();
        });
        Schema::create('academic_calendar_event_versions', function (Blueprint $table): void {
            $table->increments('academic_calendar_event_version_id');
            $table->integer('academic_calendar_event_id');
            $table->integer('version_number');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('student_registration_ends_at')->nullable();
            $table->dateTime('advisor_approval_ends_at')->nullable();
            $table->boolean('is_enforcement');
            $table->string('publication_status');
            $table->dateTime('superseded_at')->nullable();
        });
        Schema::create('students', function (Blueprint $table): void {
            $table->increments('student_id');
            $table->string('student_number')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->integer('academic_program_id')->nullable();
            $table->integer('current_academic_level_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('user_id');
            $table->string('username')->nullable();
            $table->integer('employee_id')->nullable();
            $table->timestamps();
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->increments('course_id');
            $table->string('course_code');
            $table->string('course_name');
            $table->integer('credit_hours');
            $table->integer('theoretical_hours')->default(2);
            $table->integer('practical_hours')->default(0);
        });
        Schema::create('course_offerings', function (Blueprint $table): void {
            $table->increments('course_offering_id');
            $table->integer('course_id');
            $table->integer('academic_year_id');
            $table->integer('semester_id');
            $table->integer('academic_program_id')->nullable();
            $table->integer('capacity');
            $table->integer('available_seats');
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('registration_statuses', function (Blueprint $table): void {
            $table->increments('registration_status_id');
            $table->string('status_code')->unique();
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
            $table->integer('submission_version')->default(0);
            $table->text('student_notes')->nullable();
            $table->integer('advisor_user_id')->nullable();
            $table->text('advisor_notes')->nullable();
            $table->dateTime('first_submitted_at')->nullable();
            $table->dateTime('last_submitted_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('expired_at')->nullable();
            $table->integer('registered_hours_before_approval')->nullable();
            $table->integer('request_hours_at_approval')->nullable();
            $table->integer('projected_hours_at_approval')->nullable();
            $table->integer('max_allowed_hours_at_approval')->nullable();
            $table->integer('remaining_hours_after_approval')->nullable();
            $table->timestamps();
        });
        Schema::create('student_registration_request_items', function (Blueprint $table): void {
            $table->increments('student_registration_request_item_id');
            $table->integer('student_registration_request_id');
            $table->integer('course_offering_id');
            $table->integer('student_course_registration_id')->nullable();
            $table->timestamps();
        });
        Schema::create('student_registration_request_events', function (Blueprint $table): void {
            $table->increments('student_registration_request_event_id');
            $table->integer('student_registration_request_id');
            $table->string('event_type');
            $table->integer('actor_user_id')->nullable();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->integer('submission_version')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
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
        Schema::create('course_offering_schedule_slots', function (Blueprint $table): void {
            $table->increments('course_offering_schedule_slot_id');
            $table->integer('course_offering_id');
            $table->string('component_type', 16);
            $table->tinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('location_label', 150)->nullable();
            $table->integer('created_by_user_id');
            $table->timestamps();
        });
    }
}
