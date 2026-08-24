<?php

namespace Tests\Feature;

use App\Models\SupplementaryExamPeriod;
use App\Services\AcademicCalendarPolicyService;
use App\Services\SupplementaryExamOccurrenceService;
use App\Support\AcademicCalendarPolicyResult;
use App\Support\AcademicCalendarPolicyStatus;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AcademicCalendarPhase6SupplementaryOccurrenceTest extends TestCase
{
    private SupplementaryExamOccurrenceService $occurrence;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->createSchema();
        $this->seedReferenceData();
        $this->occurrence = new SupplementaryExamOccurrenceService(app(AcademicCalendarPolicyService::class));
    }

    public function test_occurrence_uses_calendar_windows_and_inclusive_boundaries_not_period_dates(): void
    {
        $this->createWindow();

        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $this->evaluate('2026-09-01T07:59:59Z')->status);
        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $this->evaluate('2026-09-01T08:00:00Z')->status);
        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $this->evaluate('2026-09-05T16:00:00Z')->status);
        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $this->evaluate('2026-09-05T16:00:01Z')->status);

        $periodOutsideItsLegacyDates = SupplementaryExamPeriod::query()->findOrFail(1);
        self::assertFalse($periodOutsideItsLegacyDates->start_date->lte(CarbonImmutable::parse('2026-09-03T12:00:00Z'))
            && $periodOutsideItsLegacyDates->end_date->gte(CarbonImmutable::parse('2026-09-03T12:00:00Z')));
        self::assertTrue($this->occurrence->evaluateForPeriod(
            $periodOutsideItsLegacyDates,
            CarbonImmutable::parse('2026-09-03T12:00:00Z'),
        )->isOpen());

        $periodIncludingTheInstant = SupplementaryExamPeriod::query()->findOrFail(2);
        self::assertTrue($periodIncludingTheInstant->start_date->lte(CarbonImmutable::parse('2026-09-03T12:00:00Z'))
            && $periodIncludingTheInstant->end_date->gte(CarbonImmutable::parse('2026-09-03T12:00:00Z')));
        self::assertSame(
            AcademicCalendarPolicyStatus::CLOSED,
            $this->occurrence->evaluateForPeriod(
                $periodIncludingTheInstant,
                CarbonImmutable::parse('2026-09-03T12:00:00Z'),
            )->status,
        );
    }

    public function test_period_context_is_explicit_and_year_level_windows_are_wildcards(): void
    {
        $this->createWindow(event: ['semester_id' => null]);
        $at = CarbonImmutable::parse('2026-09-03T12:00:00Z');

        self::assertTrue($this->occurrence->evaluateForPeriod(
            SupplementaryExamPeriod::query()->findOrFail(1),
            $at,
        )->isOpen());
        self::assertTrue($this->occurrence->evaluateForPeriod(
            SupplementaryExamPeriod::query()->findOrFail(2),
            $at,
        )->isOpen());
        self::assertSame(AcademicCalendarPolicyStatus::INVALID_ACADEMIC_YEAR, $this->occurrence->evaluate(999, 1, $at)->status);
        self::assertSame(AcademicCalendarPolicyStatus::INVALID_SEMESTER_CONTEXT, $this->occurrence->evaluate(1, 999, $at)->status);
    }

    public function test_semester_specific_window_does_not_authorize_another_period_semester(): void
    {
        $this->createWindow(event: ['semester_id' => 1]);
        $at = CarbonImmutable::parse('2026-09-03T12:00:00Z');

        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $this->occurrence->evaluate(1, 1, $at)->status);
        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $this->occurrence->evaluate(1, 2, $at)->status);
    }

    public function test_informational_cancelled_and_superseded_windows_do_not_authorize_occurrence(): void
    {
        $this->createWindow(version: ['is_enforcement' => false]);
        $this->createWindow(event: [
            'cancelled_by_user_id' => 7,
            'cancelled_at' => '2026-08-25 00:00:00',
            'cancellation_reason' => 'cancelled fixture',
        ]);
        $this->createWindow(version: [
            'publication_status' => 'superseded',
            'superseded_at' => '2026-08-25 00:00:00',
        ]);

        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $this->evaluate('2026-09-03T12:00:00Z')->status);
    }

    public function test_published_replacement_uses_current_dates(): void
    {
        $event = $this->createWindow(version: [
            'publication_status' => 'superseded',
            'superseded_at' => '2026-08-25 00:00:00',
        ]);
        $this->addVersion($event['event_id'], [
            'version_number' => 2,
            'replaces_version_id' => $event['version_id'],
            'starts_at' => '2026-09-15 08:00:00',
            'ends_at' => '2026-09-18 16:00:00',
        ]);

        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $this->evaluate('2026-09-03T12:00:00Z')->status);
        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $this->evaluate('2026-09-16T12:00:00Z')->status);
    }

    public function test_multiple_windows_keep_any_match_and_gap_semantics(): void
    {
        $this->createWindow(version: [
            'starts_at' => '2026-09-01 00:00:00',
            'ends_at' => '2026-09-05 23:59:59',
        ]);
        $this->createWindow(version: [
            'starts_at' => '2026-09-10 00:00:00',
            'ends_at' => '2026-09-15 23:59:59',
        ]);

        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $this->evaluate('2026-09-03T12:00:00Z')->status);
        self::assertSame(AcademicCalendarPolicyStatus::CLOSED, $this->evaluate('2026-09-07T12:00:00Z')->status);
        self::assertSame(AcademicCalendarPolicyStatus::OPEN, $this->evaluate('2026-09-12T12:00:00Z')->status);
    }

    public function test_invalid_and_configuration_statuses_remain_typed(): void
    {
        $at = CarbonImmutable::parse('2026-09-03T12:00:00Z');

        DB::table('academic_years')->where('academic_year_id', 2)->update(['is_current' => 1]);
        self::assertSame(
            AcademicCalendarPolicyStatus::CALENDAR_CONFIGURATION_ERROR,
            $this->occurrence->evaluate(1, 1, $at)->status,
        );

        DB::table('academic_years')->where('academic_year_id', 2)->update(['is_current' => 0]);
        DB::table('academic_calendar_event_types')->where('event_type_code', 'supplementary_exams')->delete();
        self::assertSame(
            AcademicCalendarPolicyStatus::INVALID_EVENT_TYPE,
            $this->occurrence->evaluate(1, 1, $at)->status,
        );
    }

    public function test_snapshot_normalizes_one_immutable_utc_instant_and_forwards_period_context(): void
    {
        $calls = [];
        $policy = $this->createMock(AcademicCalendarPolicyService::class);
        $policy->expects(self::once())
            ->method('evaluate')
            ->willReturnCallback(function (string $code, ?int $year, ?int $semester, ?CarbonInterface $at) use (&$calls): AcademicCalendarPolicyResult {
                $calls[] = [$code, $year, $semester, $at];

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
        $service = new SupplementaryExamOccurrenceService($policy);
        $supplied = Carbon::parse('2026-09-03 15:00:00', 'Asia/Damascus');
        $snapshot = $service->snapshotForPeriod(SupplementaryExamPeriod::query()->findOrFail(1), $supplied);

        self::assertCount(1, $calls);
        self::assertSame('supplementary_exams', $calls[0][0]);
        self::assertSame([1, 1], [$calls[0][1], $calls[0][2]]);
        self::assertInstanceOf(CarbonImmutable::class, $calls[0][3]);
        self::assertSame('UTC', $calls[0][3]->timezoneName);
        self::assertSame('2026-09-03T12:00:00+00:00', $calls[0][3]->toIso8601String());
        self::assertSame($snapshot->evaluatedAt, $snapshot->result->evaluatedAt);
        self::assertSame([
            'supplementary_exam_period_id' => 1,
            'academic_year_id' => 1,
            'semester_id' => 1,
            'evaluated_at' => '2026-09-03T12:00:00+00:00',
            'status' => 'open',
            'is_occurring' => true,
            'reason_code' => 'effective_window_found',
        ], $snapshot->toPublicArray());
    }

    public function test_snapshot_without_supplied_time_resolves_utc_now_once(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03T12:00:00Z'));
        try {
            $snapshot = $this->occurrence->snapshotForPeriod(SupplementaryExamPeriod::query()->findOrFail(1));
        } finally {
            CarbonImmutable::setTestNow();
        }

        self::assertSame('2026-09-03T12:00:00+00:00', $snapshot->evaluatedAt->toIso8601String());
        self::assertSame($snapshot->evaluatedAt->toIso8601String(), $snapshot->result->evaluatedAt->toIso8601String());
    }

    public function test_same_context_result_does_not_vary_by_actor_or_role(): void
    {
        $this->createWindow();
        $at = CarbonImmutable::parse('2026-09-03T12:00:00Z');

        $firstViewer = $this->occurrence->evaluate(1, 1, $at);
        $secondViewer = $this->occurrence->evaluate(1, 1, $at);

        self::assertSame($firstViewer->status, $secondViewer->status);
        self::assertSame($firstViewer->matchingWindowCount, $secondViewer->matchingWindowCount);
        self::assertSame($firstViewer->reasonCode, $secondViewer->reasonCode);
    }

    public function test_physical_schema_failure_surfaces_as_infrastructure_failure(): void
    {
        Schema::drop('academic_calendar_event_types');

        $this->expectException(QueryException::class);
        $this->occurrence->evaluate(1, 1, CarbonImmutable::parse('2026-09-03T12:00:00Z'));
    }

    private function evaluate(string $at): AcademicCalendarPolicyResult
    {
        return $this->occurrence->evaluate(1, 1, CarbonImmutable::parse($at));
    }

    /** @return array{event_id: int, version_id: int} */
    private function createWindow(array $event = [], array $version = []): array
    {
        $eventId = DB::table('academic_calendar_events')->insertGetId(array_merge([
            'academic_year_id' => 1,
            'semester_id' => 1,
            'academic_calendar_event_type_id' => DB::table('academic_calendar_event_types')
                ->where('event_type_code', 'supplementary_exams')
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
            'title' => 'Supplementary examination occurrence fixture',
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
            'academic_calendar_event_type_id' => 1,
            'event_type_code' => 'supplementary_exams',
            'is_active' => 1,
        ]);
        DB::table('supplementary_exam_periods')->insert([
            [
                'supplementary_exam_period_id' => 1,
                'academic_year_id' => 1,
                'semester_id' => 1,
                'period_name' => 'Metadata outside occurrence',
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-10',
                'status' => 'grading_open',
            ],
            [
                'supplementary_exam_period_id' => 2,
                'academic_year_id' => 1,
                'semester_id' => 2,
                'period_name' => 'Metadata includes occurrence',
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-05',
                'status' => 'announced',
            ],
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
        Schema::create('supplementary_exam_periods', function (Blueprint $table): void {
            $table->increments('supplementary_exam_period_id');
            $table->integer('academic_year_id');
            $table->integer('semester_id');
            $table->string('period_name');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status');
            $table->timestamps();
        });
    }
}
