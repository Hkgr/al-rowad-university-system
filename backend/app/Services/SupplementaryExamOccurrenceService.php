<?php

namespace App\Services;

use App\Models\SupplementaryExamPeriod;
use App\Support\AcademicCalendarPolicyResult;
use App\Support\SupplementaryExamOccurrenceSnapshot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Read-only facade for supplementary-examination occurrence.
 *
 * The result is informational only. Supplementary period governance,
 * registration, grading, review, publication, and materialization remain
 * authoritative for their respective workflow actions.
 */
class SupplementaryExamOccurrenceService
{
    private const EVENT_TYPE_CODE = 'supplementary_exams';

    public function __construct(
        private readonly AcademicCalendarPolicyService $academicCalendarPolicy,
    ) {
    }

    public function evaluate(
        int $academicYearId,
        int $semesterId,
        ?CarbonInterface $at = null,
    ): AcademicCalendarPolicyResult {
        return $this->academicCalendarPolicy->evaluate(
            self::EVENT_TYPE_CODE,
            $academicYearId,
            $semesterId,
            $at,
        );
    }

    public function evaluateForPeriod(
        SupplementaryExamPeriod $period,
        ?CarbonInterface $at = null,
    ): AcademicCalendarPolicyResult {
        return $this->evaluate(
            (int) $period->academic_year_id,
            (int) $period->semester_id,
            $at,
        );
    }

    public function snapshotForPeriod(
        SupplementaryExamPeriod $period,
        ?CarbonInterface $at = null,
    ): SupplementaryExamOccurrenceSnapshot {
        $evaluatedAt = $at === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::instance($at)->utc();

        return new SupplementaryExamOccurrenceSnapshot(
            (int) $period->getKey(),
            (int) $period->academic_year_id,
            (int) $period->semester_id,
            $evaluatedAt,
            $this->evaluateForPeriod($period, $evaluatedAt),
        );
    }
}
