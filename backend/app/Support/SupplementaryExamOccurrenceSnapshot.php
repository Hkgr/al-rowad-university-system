<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Immutable occurrence state for one supplementary examination period.
 */
final readonly class SupplementaryExamOccurrenceSnapshot
{
    public function __construct(
        public int $supplementaryExamPeriodId,
        public int $academicYearId,
        public int $semesterId,
        public CarbonImmutable $evaluatedAt,
        public AcademicCalendarPolicyResult $result,
    ) {
    }

    /** @return array<string, int|string|bool|null> */
    public function toPublicArray(): array
    {
        return [
            'supplementary_exam_period_id' => $this->supplementaryExamPeriodId,
            'academic_year_id' => $this->academicYearId,
            'semester_id' => $this->semesterId,
            'evaluated_at' => $this->evaluatedAt->toIso8601String(),
            'status' => $this->result->status->value,
            'is_occurring' => $this->result->isOpen(),
            'reason_code' => $this->result->reasonCode,
        ];
    }
}
