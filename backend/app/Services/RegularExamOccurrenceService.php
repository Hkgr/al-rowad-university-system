<?php

namespace App\Services;

use App\Models\CourseOffering;
use App\Support\AcademicCalendarPolicyResult;
use App\Support\RegularExamOccurrenceSnapshot;
use App\Support\RegularExamPart;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Read-only facade for university-wide regular-exam occurrence windows.
 *
 * OPEN means the exam part is occurring at the evaluated instant. The result
 * is informational only and must not be used to gate grade entry or workflow.
 */
class RegularExamOccurrenceService
{
    public function __construct(
        private readonly AcademicCalendarPolicyService $academicCalendarPolicy,
    ) {
    }

    public function evaluate(
        RegularExamPart $part,
        int $academicYearId,
        int $semesterId,
        ?CarbonInterface $at = null,
    ): AcademicCalendarPolicyResult {
        return $this->academicCalendarPolicy->evaluate(
            $part->calendarEventTypeCode(),
            $academicYearId,
            $semesterId,
            $at,
        );
    }

    public function evaluateForOffering(
        CourseOffering $offering,
        RegularExamPart $part,
        ?CarbonInterface $at = null,
    ): AcademicCalendarPolicyResult {
        return $this->evaluate(
            $part,
            (int) $offering->academic_year_id,
            (int) $offering->semester_id,
            $at,
        );
    }

    public function snapshotForOffering(
        CourseOffering $offering,
        ?CarbonInterface $at = null,
    ): RegularExamOccurrenceSnapshot {
        $evaluatedAt = $at === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::instance($at)->utc();

        $practical = $this->evaluateForOffering($offering, RegularExamPart::PRACTICAL, $evaluatedAt);
        $theoretical = $this->evaluateForOffering($offering, RegularExamPart::THEORETICAL, $evaluatedAt);

        return new RegularExamOccurrenceSnapshot(
            (int) $offering->getKey(),
            (int) $offering->academic_year_id,
            (int) $offering->semester_id,
            $evaluatedAt,
            $practical,
            $theoretical,
        );
    }
}
