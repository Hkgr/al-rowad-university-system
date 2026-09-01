<?php

namespace App\Services;

use App\Models\AcademicCalendarEventType;
use App\Models\AcademicCalendarEvent;
use App\Models\AcademicCalendarEventVersion;
use App\Models\AcademicYear;
use App\Models\Semester;
use App\Support\AcademicCalendar;
use App\Support\AcademicCalendarPolicyResult;
use App\Support\AcademicCalendarPolicyStatus;
use App\Support\CourseRegistrationDeadlineResult;
use App\Support\CourseRegistrationPhase;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Evaluates university-wide calendar windows without enforcing any workflow.
 *
 * OPEN means at least one effective published enforcement window contains the
 * UTC evaluation instant. CLOSED is a valid policy answer. INVALID_* and
 * CALENDAR_CONFIGURATION_ERROR are typed fail-closed answers for bad context
 * or operational data, not physical database-schema readiness results.
 */
class AcademicCalendarPolicyService
{
    private const COURSE_REGISTRATION_EVENT_TYPE = 'course_registration';

    private const COURSE_REGISTRATION_REPLACEMENT_EVENT_TYPE = 'course_registration_replacement';

    /**
     * Irreversible timetable-editing boundary. A replacement calendar version
     * cannot make a registration window "not started" after an earlier
     * published version was effective at its start instant. Null means the
     * Phase 2 deadline schema is unavailable, so callers must fail closed.
     */
    public function courseRegistrationHasEverStarted(
        int $academicYearId,
        int $semesterId,
        ?CarbonInterface $at = null,
    ): ?bool {
        if (! AcademicCalendar::registrationDeadlineSchemaReady()) {
            return null;
        }

        $evaluatedAt = $at === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::instance($at)->utc();

        $versions = AcademicCalendarEventVersion::query()
            ->from('academic_calendar_event_versions as acev')
            ->join('academic_calendar_events as ace', 'ace.academic_calendar_event_id', '=', 'acev.academic_calendar_event_id')
            ->join('academic_calendar_event_types as acet', 'acet.academic_calendar_event_type_id', '=', 'ace.academic_calendar_event_type_id')
            ->where('acet.event_type_code', self::COURSE_REGISTRATION_EVENT_TYPE)
            ->where('ace.academic_year_id', $academicYearId)
            ->where(function ($query) use ($semesterId): void {
                $query->where('ace.semester_id', $semesterId)
                    ->orWhereNull('ace.semester_id');
            })
            ->where('acev.is_enforcement', true)
            ->whereNotNull('acev.published_at')
            ->whereIn('acev.publication_status', ['published', 'superseded'])
            ->get([
                'acev.starts_at',
                'acev.ends_at',
                'acev.published_at',
                'acev.superseded_at',
                'ace.cancelled_at',
            ]);

        foreach ($versions as $version) {
            $startsAt = CarbonImmutable::instance($version->starts_at)->utc();
            $endsAt = CarbonImmutable::instance($version->ends_at)->utc();
            $publishedAt = CarbonImmutable::instance($version->published_at)->utc();
            $effectiveStart = $publishedAt->gt($startsAt) ? $publishedAt : $startsAt;
            $supersededAt = $version->superseded_at === null
                ? null
                : CarbonImmutable::instance($version->superseded_at)->utc();
            $cancelledAt = $version->cancelled_at === null
                ? null
                : CarbonImmutable::parse((string) $version->cancelled_at, 'UTC');

            if ($effectiveStart->lte($evaluatedAt)
                && $effectiveStart->lte($endsAt)
                && ($supersededAt === null || $effectiveStart->lt($supersededAt))
                && ($cancelledAt === null || $effectiveStart->lt($cancelledAt))) {
                return true;
            }
        }

        return false;
    }

    public function evaluate(
        string $eventTypeCode,
        ?int $academicYearId = null,
        ?int $semesterId = null,
        ?CarbonInterface $at = null,
    ): AcademicCalendarPolicyResult {
        $evaluatedAt = $at === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::instance($at)->utc();

        $eventTypes = AcademicCalendarEventType::query()
            ->where('event_type_code', $eventTypeCode)
            ->limit(2)
            ->get(['academic_calendar_event_type_id', 'event_type_code', 'is_active']);

        if ($eventTypes->isEmpty()) {
            return $this->result(
                AcademicCalendarPolicyStatus::INVALID_EVENT_TYPE,
                $eventTypeCode,
                $academicYearId,
                $semesterId,
                $evaluatedAt,
                reasonCode: 'unknown_event_type',
            );
        }
        if ($eventTypes->count() !== 1) {
            return $this->result(
                AcademicCalendarPolicyStatus::CALENDAR_CONFIGURATION_ERROR,
                $eventTypeCode,
                $academicYearId,
                $semesterId,
                $evaluatedAt,
                reasonCode: 'event_type_code_ambiguous',
            );
        }
        $eventType = $eventTypes->first();

        $explicitYear = null;
        if ($academicYearId !== null) {
            $explicitYear = $academicYearId > 0
                ? AcademicYear::query()->find($academicYearId, $this->yearColumns())
                : null;
            if ($explicitYear === null || ! $this->isOperationalYear($explicitYear)) {
                return $this->result(
                    AcademicCalendarPolicyStatus::INVALID_ACADEMIC_YEAR,
                    $eventTypeCode,
                    $academicYearId,
                    $semesterId,
                    $evaluatedAt,
                    reasonCode: $explicitYear === null ? 'unknown_academic_year' : 'academic_year_not_operational',
                );
            }
        }

        $yearResolution = $this->resolveCanonicalYear();
        if (is_string($yearResolution)) {
            return $this->result(
                AcademicCalendarPolicyStatus::CALENDAR_CONFIGURATION_ERROR,
                $eventTypeCode,
                $academicYearId,
                $semesterId,
                $evaluatedAt,
                reasonCode: $yearResolution,
            );
        }

        if ($explicitYear !== null && (int) $explicitYear->getKey() !== (int) $yearResolution->getKey()) {
            return $this->result(
                AcademicCalendarPolicyStatus::INVALID_ACADEMIC_YEAR,
                $eventTypeCode,
                $academicYearId,
                $semesterId,
                $evaluatedAt,
                reasonCode: 'academic_year_not_operational',
            );
        }
        $academicYear = $explicitYear ?? $yearResolution;

        if ($semesterId !== null) {
            $semester = $semesterId > 0
                ? Semester::query()->find($semesterId, ['semester_id', 'is_active'])
                : null;
            if ($semester === null || ! $semester->is_active) {
                return $this->result(
                    AcademicCalendarPolicyStatus::INVALID_SEMESTER_CONTEXT,
                    $eventTypeCode,
                    (int) $academicYear->getKey(),
                    $semesterId,
                    $evaluatedAt,
                    reasonCode: $semester === null ? 'unknown_semester' : 'semester_inactive',
                );
            }
        }

        if (! $eventType->is_active) {
            return $this->result(
                AcademicCalendarPolicyStatus::CLOSED,
                $eventTypeCode,
                (int) $academicYear->getKey(),
                $semesterId,
                $evaluatedAt,
                reasonCode: 'event_type_inactive',
            );
        }

        $databaseTimestamp = $evaluatedAt->format('Y-m-d H:i:s');
        $windows = AcademicCalendarEventVersion::query()
            ->from('academic_calendar_event_versions as acev')
            ->join('academic_calendar_events as ace', 'ace.academic_calendar_event_id', '=', 'acev.academic_calendar_event_id')
            ->where('ace.academic_calendar_event_type_id', $eventType->getKey())
            ->where('ace.academic_year_id', $academicYear->getKey())
            ->whereNull('ace.cancelled_at')
            ->where('acev.publication_status', 'published')
            ->whereNull('acev.superseded_at')
            ->where('acev.is_enforcement', true)
            ->where('acev.starts_at', '<=', $databaseTimestamp)
            ->where('acev.ends_at', '>=', $databaseTimestamp);

        if ($semesterId === null) {
            $windows->whereNull('ace.semester_id');
        } else {
            $windows->where(function ($query) use ($semesterId): void {
                $query->where('ace.semester_id', $semesterId)
                    ->orWhereNull('ace.semester_id');
            });
        }

        $matchingWindowCount = $windows->count();

        return $this->result(
            $matchingWindowCount > 0
                ? AcademicCalendarPolicyStatus::OPEN
                : AcademicCalendarPolicyStatus::CLOSED,
            $eventTypeCode,
            (int) $academicYear->getKey(),
            $semesterId,
            $evaluatedAt,
            $matchingWindowCount,
            $matchingWindowCount > 0 ? 'effective_window_found' : 'no_effective_window',
        );
    }

    /**
     * Resolve the one authoritative university registration window for an
     * explicit academic term. Legacy published versions with both specialised
     * deadlines NULL retain their historical ends_at semantics.
     */
    public function courseRegistrationDeadlines(
        int $academicYearId,
        int $semesterId,
        ?CarbonInterface $at = null,
    ): CourseRegistrationDeadlineResult {
        return $this->registrationDeadlinesFor(self::COURSE_REGISTRATION_EVENT_TYPE, true, $academicYearId, $semesterId, $at);
    }

    public function courseRegistrationReplacementDeadlines(
        int $academicYearId,
        int $semesterId,
        ?CarbonInterface $at = null,
    ): CourseRegistrationDeadlineResult {
        return $this->registrationDeadlinesFor(self::COURSE_REGISTRATION_REPLACEMENT_EVENT_TYPE, false, $academicYearId, $semesterId, $at);
    }

    private function registrationDeadlinesFor(
        string $eventTypeCode,
        bool $allowLegacyFallback,
        int $academicYearId,
        int $semesterId,
        ?CarbonInterface $at,
    ): CourseRegistrationDeadlineResult {
        $evaluatedAt = $at === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::instance($at)->utc();

        $configurationError = fn (string $reason): CourseRegistrationDeadlineResult => new CourseRegistrationDeadlineResult(
            CourseRegistrationPhase::CONFIGURATION_ERROR,
            $academicYearId,
            $semesterId,
            $evaluatedAt,
            reasonCode: $reason,
        );

        if (! AcademicCalendar::registrationDeadlineSchemaReady()) {
            return $configurationError('course_registration_deadline_schema_not_ready');
        }

        $types = AcademicCalendarEventType::query()
            ->where('event_type_code', $eventTypeCode)
            ->limit(2)
            ->get(['academic_calendar_event_type_id', 'is_active']);
        if ($types->count() !== 1) {
            return $configurationError($types->isEmpty()
                ? 'course_registration_event_type_missing'
                : 'course_registration_event_type_ambiguous');
        }
        $type = $types->first();
        if (! $type->is_active) {
            return $configurationError('course_registration_event_type_inactive');
        }

        $explicitYear = $academicYearId > 0
            ? AcademicYear::query()->find($academicYearId, $this->yearColumns())
            : null;
        if ($explicitYear === null || ! $this->isOperationalYear($explicitYear)) {
            return $configurationError($explicitYear === null
                ? 'unknown_academic_year'
                : 'academic_year_not_operational');
        }
        $canonicalYear = $this->resolveCanonicalYear();
        if (is_string($canonicalYear)) {
            return $configurationError($canonicalYear);
        }
        if ((int) $canonicalYear->getKey() !== $academicYearId) {
            return $configurationError('academic_year_not_operational');
        }

        $semester = $semesterId > 0
            ? Semester::query()->find($semesterId, ['semester_id', 'is_active'])
            : null;
        if ($semester === null || ! $semester->is_active) {
            return $configurationError($semester === null ? 'unknown_semester' : 'semester_inactive');
        }

        $roots = AcademicCalendarEvent::query()
            ->where('academic_calendar_event_type_id', $type->getKey())
            ->where('academic_year_id', $academicYearId)
            ->where('semester_id', $semesterId)
            ->whereNull('cancelled_at')
            ->limit(2)
            ->get(['academic_calendar_event_id']);
        if ($roots->count() !== 1) {
            return $configurationError($roots->isEmpty()
                ? 'course_registration_window_missing'
                : 'course_registration_window_ambiguous');
        }

        $versions = AcademicCalendarEventVersion::query()
            ->from('academic_calendar_event_versions as acev')
            ->join('academic_calendar_events as ace', 'ace.academic_calendar_event_id', '=', 'acev.academic_calendar_event_id')
            ->where('ace.academic_calendar_event_id', $roots->first()->getKey())
            ->where('acev.publication_status', 'published')
            ->whereNull('acev.superseded_at')
            ->limit(2)
            ->get([
                'ace.academic_calendar_event_id',
                'acev.academic_calendar_event_version_id',
                'acev.starts_at',
                'acev.ends_at',
                'acev.student_registration_ends_at',
                'acev.advisor_approval_ends_at',
                'acev.is_enforcement',
            ]);

        if ($versions->count() !== 1) {
            return $configurationError($versions->isEmpty()
                ? 'course_registration_published_version_missing'
                : 'course_registration_published_version_ambiguous');
        }
        $version = $versions->first();
        if (! $version->is_enforcement) {
            return $configurationError('course_registration_window_not_enforcement');
        }

        $startsAt = CarbonImmutable::instance($version->starts_at)->utc();
        $genericEndsAt = CarbonImmutable::instance($version->ends_at)->utc();
        $studentValue = $version->student_registration_ends_at;
        $advisorValue = $version->advisor_approval_ends_at;
        $bothSpecializedMissing = $studentValue === null && $advisorValue === null;
        if ($bothSpecializedMissing && ! $allowLegacyFallback) {
            return $configurationError('course_registration_replacement_deadlines_missing');
        }
        $legacyFallback = $allowLegacyFallback && $bothSpecializedMissing;

        if (($studentValue === null) !== ($advisorValue === null)) {
            return $configurationError('course_registration_deadlines_incomplete');
        }

        $studentEndsAt = $legacyFallback
            ? $genericEndsAt
            : CarbonImmutable::instance($studentValue)->utc();
        $advisorEndsAt = $legacyFallback
            ? $genericEndsAt
            : CarbonImmutable::instance($advisorValue)->utc();

        if ($startsAt->gt($studentEndsAt)
            || $studentEndsAt->gt($advisorEndsAt)
            || (! $legacyFallback && ! $genericEndsAt->equalTo($advisorEndsAt))) {
            return $configurationError('course_registration_deadlines_invalid');
        }

        $phase = match (true) {
            $evaluatedAt->lt($startsAt) => CourseRegistrationPhase::NOT_STARTED,
            $evaluatedAt->lte($studentEndsAt) => CourseRegistrationPhase::STUDENT_OPEN,
            $evaluatedAt->lte($advisorEndsAt) => CourseRegistrationPhase::ADVISOR_REVIEW,
            default => CourseRegistrationPhase::CLOSED,
        };

        return new CourseRegistrationDeadlineResult(
            $phase,
            $academicYearId,
            $semesterId,
            $evaluatedAt,
            $startsAt,
            $studentEndsAt,
            $advisorEndsAt,
            (int) $version->academic_calendar_event_id,
            (int) $version->academic_calendar_event_version_id,
            match ($phase) {
                CourseRegistrationPhase::NOT_STARTED => 'course_registration_not_started',
                CourseRegistrationPhase::STUDENT_OPEN => 'course_registration_student_open',
                CourseRegistrationPhase::ADVISOR_REVIEW => 'course_registration_advisor_review',
                CourseRegistrationPhase::CLOSED => 'course_registration_closed',
                CourseRegistrationPhase::CONFIGURATION_ERROR => 'course_registration_configuration_error',
            },
            $legacyFallback,
        );
    }

    /**
     * @return AcademicYear|string Canonical year or a stable configuration reason code.
     */
    private function resolveCanonicalYear(): AcademicYear|string
    {
        $candidates = AcademicYear::query()
            ->where('is_current', true)
            ->orWhere('calendar_lifecycle_status', 'active')
            ->get($this->yearColumns());
        $current = $candidates->where('is_current', true);
        $lifecycleActive = $candidates->where('calendar_lifecycle_status', 'active');

        if ($current->isEmpty()) {
            return 'current_academic_year_missing';
        }
        if ($current->count() !== 1) {
            return 'current_academic_year_ambiguous';
        }
        if ($lifecycleActive->isEmpty()) {
            return 'lifecycle_active_academic_year_missing';
        }
        if ($lifecycleActive->count() !== 1) {
            return 'lifecycle_active_academic_year_ambiguous';
        }

        $year = $current->first();
        if ((int) $year->getKey() !== (int) $lifecycleActive->first()->getKey()) {
            return 'current_lifecycle_academic_year_mismatch';
        }
        if (! $this->isOperationalYear($year)) {
            return 'current_academic_year_not_operational';
        }

        return $year;
    }

    private function isOperationalYear(AcademicYear $year): bool
    {
        return $year->is_current
            && $year->is_active
            && $year->calendar_lifecycle_status === 'active';
    }

    /** @return list<string> */
    private function yearColumns(): array
    {
        return ['academic_year_id', 'is_current', 'is_active', 'calendar_lifecycle_status'];
    }

    private function result(
        AcademicCalendarPolicyStatus $status,
        string $eventTypeCode,
        ?int $academicYearId,
        ?int $semesterId,
        CarbonImmutable $evaluatedAt,
        int $matchingWindowCount = 0,
        ?string $reasonCode = null,
    ): AcademicCalendarPolicyResult {
        return new AcademicCalendarPolicyResult(
            $status,
            $eventTypeCode,
            $academicYearId,
            $semesterId,
            $evaluatedAt,
            $matchingWindowCount,
            $reasonCode,
        );
    }
}
