<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Immutable, temporally atomic occurrence state for one course offering.
 */
final readonly class RegularExamOccurrenceSnapshot
{
    public function __construct(
        public int $courseOfferingId,
        public int $academicYearId,
        public int $semesterId,
        public CarbonImmutable $evaluatedAt,
        public AcademicCalendarPolicyResult $practical,
        public AcademicCalendarPolicyResult $theoretical,
    ) {
    }
}
