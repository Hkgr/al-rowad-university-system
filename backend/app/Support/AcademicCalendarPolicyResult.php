<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Immutable temporal-policy answer. It intentionally contains no calendar
 * audit, actor, change-reason, or cancellation-reason data.
 */
final readonly class AcademicCalendarPolicyResult
{
    public function __construct(
        public AcademicCalendarPolicyStatus $status,
        public string $eventTypeCode,
        public ?int $academicYearId,
        public ?int $semesterId,
        public CarbonImmutable $evaluatedAt,
        public int $matchingWindowCount = 0,
        public ?string $reasonCode = null,
    ) {
    }

    public function isOpen(): bool
    {
        return $this->status === AcademicCalendarPolicyStatus::OPEN;
    }
}
