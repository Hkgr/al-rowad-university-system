<?php

namespace Tests\Feature;

use App\Services\AcademicCalendarPolicyService;
use App\Support\AcademicCalendarPolicyResult;
use App\Support\AcademicCalendarPolicyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AcademicCalendarPhase3PolicyServiceTest extends TestCase
{
    private AcademicCalendarPolicyService $policy;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->createSchema();
        $this->seedReferenceData();
        $this->policy = app(AcademicCalendarPolicyService::class);
    }

    public function test_published_enforcement_window_uses_inclusive_boundaries_and_utc(): void
    {
        $this->createWindow();

        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $this->evaluate('2026-09-01T07:59:59Z')->status);
        $start = $this->policy->evaluate(
            'course_registration',
            1,
            1,
            CarbonImmutable::parse('2026-09-01 11:00:00', 'Asia/Damascus'),
        );
        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $start->status);
        self::assertTrue($start->isOpen());
        self::assertSame('UTC', $start->evaluatedAt->timezoneName);
        self::assertSame('2026-09-01T08:00:00+00:00', $start->evaluatedAt->toIso8601String());
        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $this->evaluate('2026-09-03T12:00:00Z')->status);
        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $this->evaluate('2026-09-05T16:00:00Z')->status);
        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $this->evaluate('2026-09-05T16:00:01Z')->status);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03T12:00:00Z'));
        try {
            self::assertTrue($this->policy->evaluate('course_registration', 1, 1)->isOpen());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_only_current_published_enforcement_revision_can_authorize(): void
    {
        $draft = $this->createWindow(version: ['publication_status' => 'draft']);
        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $this->evaluate('2026-09-03T12:00:00Z')->status);
        DB::table('academic_calendar_events')->where('academic_calendar_event_id', $draft['event_id'])->delete();

        $event = $this->createWindow(version: [
            'publication_status' => 'superseded',
            'superseded_at' => '2026-08-20 00:00:00',
        ]);
        $this->addVersion($event['event_id'], [
            'version_number' => 2,
            'replaces_version_id' => $event['version_id'],
            'starts_at' => '2026-09-15 08:00:00',
            'ends_at' => '2026-09-18 16:00:00',
        ]);

        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $this->evaluate('2026-09-05T12:00:00Z')->status);
        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $this->evaluate('2026-09-16T12:00:00Z')->status);
    }

    public function test_informational_and_cancelled_events_do_not_authorize(): void
    {
        $this->createWindow(version: ['is_enforcement' => false]);
        $this->createWindow(event: [
            'cancelled_by_user_id' => 7,
            'cancelled_at' => '2026-08-25 00:00:00',
            'cancellation_reason' => 'cancelled',
        ]);

        $result = $this->evaluate('2026-09-03T12:00:00Z');
        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $result->status);
        self::assertSame(0, $result->matchingWindowCount);
    }

    public function test_event_type_resolution_distinguishes_wrong_unknown_and_inactive_types(): void
    {
        $this->createWindow(event: ['academic_calendar_event_type_id' => 2]);
        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $this->evaluate('2026-09-03T12:00:00Z')->status);

        $unknown = $this->policy->evaluate('not_a_calendar_code', 1, 1, CarbonImmutable::parse('2026-09-03T12:00:00Z'));
        self::assertSame(AcademicCalendarPolicyStatus::INVALID_EVENT_TYPE, $unknown->status);
        self::assertSame('unknown_event_type', $unknown->reasonCode);

        $this->createWindow(event: ['academic_calendar_event_type_id' => 3]);
        $inactive = $this->policy->evaluate('inactive_window', 1, 1, CarbonImmutable::parse('2026-09-03T12:00:00Z'));
        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $inactive->status);
        self::assertSame('event_type_inactive', $inactive->reasonCode);
    }

    public function test_multiple_and_overlapping_windows_use_any_match_semantics(): void
    {
        $this->createWindow(version: ['starts_at' => '2026-09-01 00:00:00', 'ends_at' => '2026-09-10 23:59:59']);
        $this->createWindow(version: ['starts_at' => '2026-09-15 00:00:00', 'ends_at' => '2026-09-18 23:59:59']);

        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $this->evaluate('2026-09-05T00:00:00Z')->status);
        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $this->evaluate('2026-09-12T00:00:00Z')->status);
        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $this->evaluate('2026-09-16T00:00:00Z')->status);

        $this->createWindow(version: ['starts_at' => '2026-09-16 00:00:00', 'ends_at' => '2026-09-17 00:00:00']);
        $overlap = $this->evaluate('2026-09-16T12:00:00Z');
        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $overlap->status);
        self::assertSame(2, $overlap->matchingWindowCount);
    }

    public function test_explicit_and_omitted_year_use_only_the_canonical_operational_year(): void
    {
        $this->createWindow();
        $this->createWindow(
            event: ['academic_year_id' => 2],
            version: ['starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-10 23:59:59'],
        );

        $explicit = $this->evaluate('2026-09-03T12:00:00Z');
        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $explicit->status);
        self::assertSame(1, $explicit->academicYearId);

        $omitted = $this->policy->evaluate('course_registration', null, 1, CarbonImmutable::parse('2026-09-03T12:00:00Z'));
        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $omitted->status);
        self::assertSame(1, $omitted->academicYearId);

        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $this->evaluate('2026-10-05T12:00:00Z')->status);
        self::assertSame(AcademicCalendarPolicyStatus::INVALID_ACADEMIC_YEAR, $this->policy->evaluate('course_registration', 2, 1)->status);
        self::assertSame(AcademicCalendarPolicyStatus::INVALID_ACADEMIC_YEAR, $this->policy->evaluate('course_registration', 999, 1)->status);
    }

    public function test_invalid_current_year_data_returns_configuration_errors(): void
    {
        DB::table('academic_years')->where('academic_year_id', 1)->update(['is_current' => 0]);
        $missing = $this->policy->evaluate('course_registration');
        self::assertSame(AcademicCalendarPolicyStatus::CALENDAR_CONFIGURATION_ERROR, $missing->status);
        self::assertSame('current_academic_year_missing', $missing->reasonCode);

        DB::table('academic_years')->where('academic_year_id', 1)->update(['is_current' => 1]);
        DB::table('academic_years')->where('academic_year_id', 2)->update(['is_current' => 1]);
        self::assertSame('current_academic_year_ambiguous', $this->policy->evaluate('course_registration')->reasonCode);

        DB::table('academic_years')->where('academic_year_id', 2)->update(['is_current' => 0]);
        DB::table('academic_years')->where('academic_year_id', 1)->update(['calendar_lifecycle_status' => 'draft']);
        DB::table('academic_years')->where('academic_year_id', 3)->update(['calendar_lifecycle_status' => 'active']);
        self::assertSame('current_lifecycle_academic_year_mismatch', $this->policy->evaluate('course_registration')->reasonCode);

        DB::table('academic_years')->where('academic_year_id', 3)->update(['calendar_lifecycle_status' => 'draft']);
        DB::table('academic_years')->where('academic_year_id', 1)->update(['calendar_lifecycle_status' => 'active', 'is_active' => 0]);
        self::assertSame('current_academic_year_not_operational', $this->policy->evaluate('course_registration')->reasonCode);
    }

    public function test_semester_specific_window_requires_matching_explicit_context(): void
    {
        $this->createWindow();

        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $this->evaluate('2026-09-03T12:00:00Z', 1)->status);
        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $this->evaluate('2026-09-03T12:00:00Z', 2)->status);
        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $this->policy->evaluate('course_registration', 1, null, CarbonImmutable::parse('2026-09-03T12:00:00Z'))->status);
        self::assertSame(AcademicCalendarPolicyStatus::INVALID_SEMESTER_CONTEXT, $this->evaluate('2026-09-03T12:00:00Z', 999)->status);
        self::assertSame(AcademicCalendarPolicyStatus::INVALID_SEMESTER_CONTEXT, $this->evaluate('2026-09-03T12:00:00Z', 3)->status);
    }

    public function test_year_level_window_is_a_wildcard_for_explicit_semester_and_matches_omitted_context(): void
    {
        $this->createWindow(event: ['semester_id' => null]);

        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $this->evaluate('2026-09-03T12:00:00Z', 1)->status);
        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $this->policy->evaluate('course_registration', 1, null, CarbonImmutable::parse('2026-09-03T12:00:00Z'))->status);
    }

    public function test_result_contains_only_narrow_policy_context(): void
    {
        $this->createWindow();
        $result = $this->evaluate('2026-09-03T12:00:00Z');

        self::assertInstanceOf(AcademicCalendarPolicyResult::class, $result);
        self::assertSame('course_registration', $result->eventTypeCode);
        self::assertSame(1, $result->academicYearId);
        self::assertSame(1, $result->semesterId);
        self::assertSame(1, $result->matchingWindowCount);
        foreach (['change_reason', 'created_by_user_id', 'published_by_user_id', 'cancellation_reason'] as $privateField) {
            self::assertArrayNotHasKey($privateField, get_object_vars($result));
        }
    }

    public function test_physical_schema_failure_is_not_converted_to_a_policy_result(): void
    {
        Schema::drop('academic_calendar_event_types');

        $this->expectException(QueryException::class);
        $this->policy->evaluate('course_registration');
    }

    private function evaluate(string $at, ?int $semesterId = 1): AcademicCalendarPolicyResult
    {
        return $this->policy->evaluate('course_registration', 1, $semesterId, CarbonImmutable::parse($at));
    }

    /** @return array{event_id: int, version_id: int} */
    private function createWindow(array $event = [], array $version = []): array
    {
        $eventId = DB::table('academic_calendar_events')->insertGetId(array_merge([
            'academic_year_id' => 1,
            'semester_id' => 1,
            'academic_calendar_event_type_id' => 1,
            'created_by_user_id' => 7,
            'created_at' => '2026-08-20 00:00:00',
            'cancelled_by_user_id' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ], $event));
        $versionId = $this->addVersion($eventId, $version);

        return ['event_id' => $eventId, 'version_id' => $versionId];
    }

    private function addVersion(int $eventId, array $version = []): int
    {
        return DB::table('academic_calendar_event_versions')->insertGetId(array_merge([
            'academic_calendar_event_id' => $eventId,
            'version_number' => 1,
            'replaces_version_id' => null,
            'title' => 'Registration window',
            'public_notes' => null,
            'starts_at' => '2026-09-01 08:00:00',
            'ends_at' => '2026-09-05 16:00:00',
            'is_enforcement' => 1,
            'change_reason' => 'test fixture',
            'created_by_user_id' => 7,
            'created_at' => '2026-08-20 00:00:00',
            'publication_status' => 'published',
            'published_by_user_id' => 7,
            'published_at' => '2026-08-20 00:00:00',
            'superseded_at' => null,
            'published_event_slot' => null,
        ], $version));
    }

    private function seedReferenceData(): void
    {
        DB::table('academic_years')->insert([
            ['academic_year_id' => 1, 'is_current' => 1, 'is_active' => 1, 'calendar_lifecycle_status' => 'active'],
            ['academic_year_id' => 2, 'is_current' => 0, 'is_active' => 1, 'calendar_lifecycle_status' => 'closed'],
            ['academic_year_id' => 3, 'is_current' => 0, 'is_active' => 1, 'calendar_lifecycle_status' => 'draft'],
        ]);
        DB::table('semesters')->insert([
            ['semester_id' => 1, 'semester_code' => 'first', 'is_active' => 1],
            ['semester_id' => 2, 'semester_code' => 'second', 'is_active' => 1],
            ['semester_id' => 3, 'semester_code' => 'summer', 'is_active' => 0],
        ]);
        DB::table('academic_calendar_event_types')->insert([
            ['academic_calendar_event_type_id' => 1, 'event_type_code' => 'course_registration', 'is_active' => 1],
            ['academic_calendar_event_type_id' => 2, 'event_type_code' => 'withdrawal', 'is_active' => 1],
            ['academic_calendar_event_type_id' => 3, 'event_type_code' => 'inactive_window', 'is_active' => 0],
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
            $table->text('public_notes')->nullable();
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
    }
}
