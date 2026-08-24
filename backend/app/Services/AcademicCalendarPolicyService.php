<?php

namespace App\Services;

use App\Models\AcademicCalendarEventType;
use App\Models\AcademicCalendarEventVersion;
use App\Models\AcademicYear;
use App\Models\Semester;
use App\Support\AcademicCalendarPolicyResult;
use App\Support\AcademicCalendarPolicyStatus;
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
