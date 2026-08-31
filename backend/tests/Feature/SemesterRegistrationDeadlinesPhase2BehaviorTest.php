<?php

namespace Tests\Feature;

use App\Exceptions\AcademicCalendarException;
use App\Exceptions\RegistrationRequestException;
use App\Models\AcademicCalendarEventType;
use App\Models\StudentRegistrationRequest;
use App\Services\AcademicCalendarService;
use App\Services\AcademicCalendarPolicyService;
use App\Services\AcademicRequirementService;
use App\Services\AcademicTermResolver;
use App\Services\DataScopeService;
use App\Services\RegistrationRequestService;
use App\Services\RegistrationService;
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

    private function requestService(): RegistrationRequestService
    {
        return new RegistrationRequestService(
            Mockery::mock(RegistrationService::class),
            Mockery::mock(AcademicTermResolver::class),
            Mockery::mock(DataScopeService::class),
            Mockery::mock(AcademicRequirementService::class),
        );
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
            $table->boolean('is_current');
            $table->boolean('is_active');
            $table->string('calendar_lifecycle_status');
        });
        Schema::create('semesters', function (Blueprint $table): void {
            $table->increments('semester_id');
            $table->string('semester_code');
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
            $table->timestamps();
        });
        Schema::create('student_registration_requests', function (Blueprint $table): void {
            $table->increments('student_registration_request_id');
            $table->integer('student_id');
            $table->integer('academic_year_id');
            $table->integer('semester_id');
            $table->string('status');
            $table->integer('submission_version')->default(0);
            $table->text('advisor_notes')->nullable();
            $table->dateTime('expired_at')->nullable();
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
        });
    }
}
