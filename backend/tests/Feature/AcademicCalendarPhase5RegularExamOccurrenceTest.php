<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Services\AcademicCalendarPolicyService;
use App\Services\RegularExamOccurrenceService;
use App\Support\AcademicCalendarPolicyResult;
use App\Support\AcademicCalendarPolicyStatus;
use App\Support\RegularExamPart;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AcademicCalendarPhase5RegularExamOccurrenceTest extends TestCase
{
    private RegularExamOccurrenceService $occurrence;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->createSchema();
        $this->seedReferenceData();
        $this->occurrence = new RegularExamOccurrenceService(app(AcademicCalendarPolicyService::class));
    }

    public function test_practical_and_theoretical_windows_are_independent_and_keep_inclusive_boundaries(): void
    {
        $this->createWindow('practical_exams');
        $this->createWindow('theoretical_exams', version: [
            'starts_at' => '2026-09-03 00:00:00',
            'ends_at' => '2026-09-10 12:00:00',
        ]);

        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $this->evaluate(RegularExamPart::PRACTICAL, '2026-09-01T07:59:59Z')->status);
        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $this->evaluate(RegularExamPart::PRACTICAL, '2026-09-01T08:00:00Z')->status);
        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $this->evaluate(RegularExamPart::PRACTICAL, '2026-09-05T16:00:00Z')->status);
        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $this->evaluate(RegularExamPart::PRACTICAL, '2026-09-05T16:00:01Z')->status);
        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $this->evaluate(RegularExamPart::THEORETICAL, '2026-09-03T00:00:00Z')->status);
        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $this->evaluate(RegularExamPart::THEORETICAL, '2026-09-10T12:00:00Z')->status);

        $practicalOnly = $this->snapshot('2026-09-02T12:00:00Z');
        self::assertTrue($practicalOnly->practical->isOpen());
        self::assertFalse($practicalOnly->theoretical->isOpen());

        $both = $this->snapshot('2026-09-04T12:00:00Z');
        self::assertTrue($both->practical->isOpen());
        self::assertTrue($both->theoretical->isOpen());

        $theoreticalOnly = $this->snapshot('2026-09-08T12:00:00Z');
        self::assertFalse($theoreticalOnly->practical->isOpen());
        self::assertTrue($theoreticalOnly->theoretical->isOpen());

        $neither = $this->snapshot('2026-09-11T12:00:00Z');
        self::assertFalse($neither->practical->isOpen());
        self::assertFalse($neither->theoretical->isOpen());
    }

    public function test_offering_context_is_explicit_and_year_level_windows_are_wildcards(): void
    {
        $this->createWindow('practical_exams', event: ['semester_id' => null]);
        $this->createWindow('theoretical_exams', event: ['semester_id' => null]);
        $offering = CourseOffering::query()->findOrFail(2);
        $at = CarbonImmutable::parse('2026-09-03T12:00:00Z');

        self::assertTrue($this->occurrence->evaluateForOffering($offering, RegularExamPart::PRACTICAL, $at)->isOpen());
        self::assertTrue($this->occurrence->evaluateForOffering($offering, RegularExamPart::THEORETICAL, $at)->isOpen());

        $wrongSemester = $this->evaluate(RegularExamPart::PRACTICAL, '2026-09-03T12:00:00Z', semesterId: 2);
        self::assertTrue($wrongSemester->isOpen(), 'A year-level event must match every explicit active offering semester.');

        self::assertSame(
            AcademicCalendarPolicyStatus::INVALID_ACADEMIC_YEAR,
            $this->occurrence->evaluate(RegularExamPart::PRACTICAL, 2, 1, $at)->status,
        );
        self::assertSame(
            AcademicCalendarPolicyStatus::INVALID_SEMESTER_CONTEXT,
            $this->occurrence->evaluate(RegularExamPart::PRACTICAL, 1, 3, $at)->status,
        );
    }

    public function test_semester_specific_window_does_not_authorize_a_different_offering_semester(): void
    {
        $this->createWindow('practical_exams', event: ['semester_id' => 1]);

        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $this->evaluate(RegularExamPart::PRACTICAL, '2026-09-03T12:00:00Z', semesterId: 1)->status);
        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $this->evaluate(RegularExamPart::PRACTICAL, '2026-09-03T12:00:00Z', semesterId: 2)->status);
    }

    public function test_informational_cancelled_and_superseded_windows_do_not_authorize_occurrence(): void
    {
        $this->createWindow('practical_exams', version: ['is_enforcement' => false]);
        $this->createWindow('practical_exams', event: [
            'cancelled_by_user_id' => 7,
            'cancelled_at' => '2026-08-25 00:00:00',
            'cancellation_reason' => 'cancelled practical fixture',
        ]);
        $this->createWindow('theoretical_exams', event: [
            'cancelled_by_user_id' => 7,
            'cancelled_at' => '2026-08-25 00:00:00',
            'cancellation_reason' => 'cancelled fixture',
        ]);
        $this->createWindow('practical_exams', version: [
            'publication_status' => 'superseded',
            'superseded_at' => '2026-08-25 00:00:00',
        ]);

        $snapshot = $this->snapshot('2026-09-03T12:00:00Z');
        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $snapshot->practical->status);
        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $snapshot->theoretical->status);
    }

    public function test_published_replacement_uses_current_dates(): void
    {
        $event = $this->createWindow('practical_exams', version: [
            'publication_status' => 'superseded',
            'superseded_at' => '2026-08-25 00:00:00',
        ]);
        $this->addVersion($event['event_id'], [
            'version_number' => 2,
            'replaces_version_id' => $event['version_id'],
            'starts_at' => '2026-09-15 08:00:00',
            'ends_at' => '2026-09-18 16:00:00',
        ]);

        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $this->evaluate(RegularExamPart::PRACTICAL, '2026-09-03T12:00:00Z')->status);
        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $this->evaluate(RegularExamPart::PRACTICAL, '2026-09-16T12:00:00Z')->status);
    }

    public function test_multiple_windows_keep_any_match_and_gap_semantics(): void
    {
        $this->createWindow('practical_exams', version: [
            'starts_at' => '2026-09-01 00:00:00',
            'ends_at' => '2026-09-05 23:59:59',
        ]);
        $this->createWindow('practical_exams', version: [
            'starts_at' => '2026-09-10 00:00:00',
            'ends_at' => '2026-09-15 23:59:59',
        ]);

        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $this->evaluate(RegularExamPart::PRACTICAL, '2026-09-03T12:00:00Z')->status);
        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $this->evaluate(RegularExamPart::PRACTICAL, '2026-09-07T12:00:00Z')->status);
        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $this->evaluate(RegularExamPart::PRACTICAL, '2026-09-12T12:00:00Z')->status);
    }

    public function test_invalid_and_configuration_statuses_are_not_converted_to_closed(): void
    {
        $at = CarbonImmutable::parse('2026-09-03T12:00:00Z');

        self::assertSame(
            AcademicCalendarPolicyStatus::INVALID_ACADEMIC_YEAR,
            $this->occurrence->evaluate(RegularExamPart::PRACTICAL, 999, 1, $at)->status,
        );
        self::assertSame(
            AcademicCalendarPolicyStatus::INVALID_SEMESTER_CONTEXT,
            $this->occurrence->evaluate(RegularExamPart::PRACTICAL, 1, 999, $at)->status,
        );

        DB::table('academic_years')->where('academic_year_id', 2)->update(['is_current' => 1]);
        self::assertSame(
            AcademicCalendarPolicyStatus::CALENDAR_CONFIGURATION_ERROR,
            $this->occurrence->evaluate(RegularExamPart::PRACTICAL, 1, 1, $at)->status,
        );

        DB::table('academic_years')->where('academic_year_id', 2)->update(['is_current' => 0]);
        DB::table('academic_calendar_event_types')->where('event_type_code', 'practical_exams')->delete();
        self::assertSame(
            AcademicCalendarPolicyStatus::INVALID_EVENT_TYPE,
            $this->occurrence->evaluate(RegularExamPart::PRACTICAL, 1, 1, $at)->status,
        );
    }

    public function test_snapshot_resolves_one_immutable_utc_instant_for_both_parts(): void
    {
        $received = [];
        $policy = $this->createMock(AcademicCalendarPolicyService::class);
        $policy->expects(self::exactly(2))
            ->method('evaluate')
            ->willReturnCallback(function (string $code, ?int $year, ?int $semester, ?CarbonInterface $at) use (&$received): AcademicCalendarPolicyResult {
                $received[] = $at;

                return new AcademicCalendarPolicyResult(
                    AcademicCalendarPolicyStatus::OPEN,
                    $code,
                    $year,
                    $semester,
                    $at,
                    1,
                    'effective_window_found',
                );
            });
        $service = new RegularExamOccurrenceService($policy);
        $supplied = Carbon::parse('2026-09-03 15:00:00', 'Asia/Damascus');
        $snapshot = $service->snapshotForOffering(CourseOffering::query()->findOrFail(1), $supplied);

        self::assertCount(2, $received);
        self::assertInstanceOf(CarbonImmutable::class, $received[0]);
        self::assertSame($received[0], $received[1]);
        self::assertSame('UTC', $received[0]->timezoneName);
        self::assertSame('2026-09-03T12:00:00+00:00', $received[0]->toIso8601String());
        self::assertSame($snapshot->evaluatedAt, $snapshot->practical->evaluatedAt);
        self::assertSame($snapshot->practical->evaluatedAt, $snapshot->theoretical->evaluatedAt);
    }

    public function test_snapshot_without_supplied_time_resolves_now_once(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03T12:00:00Z'));
        try {
            $snapshot = $this->occurrence->snapshotForOffering(CourseOffering::query()->findOrFail(1));
        } finally {
            CarbonImmutable::setTestNow();
        }

        self::assertSame('2026-09-03T12:00:00+00:00', $snapshot->evaluatedAt->toIso8601String());
        self::assertTrue($snapshot->practical->evaluatedAt->equalTo($snapshot->theoretical->evaluatedAt));
        self::assertSame($snapshot->practical->evaluatedAt->toIso8601String(), $snapshot->theoretical->evaluatedAt->toIso8601String());
    }

    public function test_mapping_and_offering_context_are_forwarded_without_role_context(): void
    {
        $calls = [];
        $policy = $this->createMock(AcademicCalendarPolicyService::class);
        $policy->expects(self::exactly(2))
            ->method('evaluate')
            ->willReturnCallback(function (string $code, ?int $year, ?int $semester, ?CarbonInterface $at) use (&$calls): AcademicCalendarPolicyResult {
                $calls[] = [$code, $year, $semester, $at];

                return new AcademicCalendarPolicyResult(
                    AcademicCalendarPolicyStatus::CLOSED,
                    $code,
                    $year,
                    $semester,
                    CarbonImmutable::instance($at)->utc(),
                    reasonCode: 'no_effective_window',
                );
            });
        $service = new RegularExamOccurrenceService($policy);
        $offering = CourseOffering::query()->findOrFail(2);
        $at = CarbonImmutable::parse('2026-09-03T12:00:00Z');

        $service->evaluateForOffering($offering, RegularExamPart::PRACTICAL, $at);
        $service->evaluateForOffering($offering, RegularExamPart::THEORETICAL, $at);

        self::assertSame('practical_exams', $calls[0][0]);
        self::assertSame('theoretical_exams', $calls[1][0]);
        self::assertSame([1, 1], [$calls[0][1], $calls[1][1]]);
        self::assertSame([2, 2], [$calls[0][2], $calls[1][2]]);
        self::assertSame($at, $calls[0][3]);
        self::assertSame($at, $calls[1][3]);
    }

    public function test_same_context_result_does_not_vary_by_actor_or_role(): void
    {
        $this->createWindow('practical_exams');
        $at = CarbonImmutable::parse('2026-09-03T12:00:00Z');

        $firstViewer = $this->occurrence->evaluate(RegularExamPart::PRACTICAL, 1, 1, $at);
        $secondViewer = $this->occurrence->evaluate(RegularExamPart::PRACTICAL, 1, 1, $at);

        self::assertSame($firstViewer->status, $secondViewer->status);
        self::assertSame($firstViewer->matchingWindowCount, $secondViewer->matchingWindowCount);
        self::assertSame($firstViewer->reasonCode, $secondViewer->reasonCode);
    }

    public function test_physical_schema_failure_surfaces_as_infrastructure_failure(): void
    {
        Schema::drop('academic_calendar_event_types');

        $this->expectException(QueryException::class);
        $this->occurrence->evaluate(
            RegularExamPart::PRACTICAL,
            1,
            1,
            CarbonImmutable::parse('2026-09-03T12:00:00Z'),
        );
    }

    private function evaluate(
        RegularExamPart $part,
        string $at,
        int $academicYearId = 1,
        int $semesterId = 1,
    ): AcademicCalendarPolicyResult {
        return $this->occurrence->evaluate(
            $part,
            $academicYearId,
            $semesterId,
            CarbonImmutable::parse($at),
        );
    }

    private function snapshot(string $at)
    {
        return $this->occurrence->snapshotForOffering(
            CourseOffering::query()->findOrFail(1),
            CarbonImmutable::parse($at),
        );
    }

    /** @return array{event_id: int, version_id: int} */
    private function createWindow(string $eventTypeCode, array $event = [], array $version = []): array
    {
        $eventId = DB::table('academic_calendar_events')->insertGetId(array_merge([
            'academic_year_id' => 1,
            'semester_id' => 1,
            'academic_calendar_event_type_id' => DB::table('academic_calendar_event_types')
                ->where('event_type_code', $eventTypeCode)
                ->value('academic_calendar_event_type_id'),
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
            'title' => 'Regular exam occurrence fixture',
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
        ]);
        DB::table('semesters')->insert([
            ['semester_id' => 1, 'semester_code' => 'first', 'is_active' => 1],
            ['semester_id' => 2, 'semester_code' => 'second', 'is_active' => 1],
            ['semester_id' => 3, 'semester_code' => 'summer', 'is_active' => 0],
        ]);
        DB::table('academic_calendar_event_types')->insert([
            ['academic_calendar_event_type_id' => 1, 'event_type_code' => 'practical_exams', 'is_active' => 1],
            ['academic_calendar_event_type_id' => 2, 'event_type_code' => 'theoretical_exams', 'is_active' => 1],
        ]);
        DB::table('course_offerings')->insert([
            ['course_offering_id' => 1, 'academic_year_id' => 1, 'semester_id' => 1],
            ['course_offering_id' => 2, 'academic_year_id' => 1, 'semester_id' => 2],
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
        Schema::create('course_offerings', function (Blueprint $table): void {
            $table->increments('course_offering_id');
            $table->integer('academic_year_id');
            $table->integer('semester_id');
        });
    }
}
