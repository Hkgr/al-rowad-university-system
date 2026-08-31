<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final readonly class CourseRegistrationDeadlineResult
{
    public function __construct(
        public CourseRegistrationPhase $phase,
        public int $academicYearId,
        public int $semesterId,
        public CarbonImmutable $evaluatedAt,
        public ?CarbonImmutable $startsAt = null,
        public ?CarbonImmutable $studentRegistrationEndsAt = null,
        public ?CarbonImmutable $advisorApprovalEndsAt = null,
        public ?int $academicCalendarEventId = null,
        public ?int $academicCalendarEventVersionId = null,
        public ?string $reasonCode = null,
        public bool $legacyDeadlineFallback = false,
    ) {
    }

    public function isStudentOpen(): bool
    {
        return $this->phase === CourseRegistrationPhase::STUDENT_OPEN;
    }

    public function isAdvisorDecisionOpen(): bool
    {
        return in_array($this->phase, [
            CourseRegistrationPhase::STUDENT_OPEN,
            CourseRegistrationPhase::ADVISOR_REVIEW,
        ], true);
    }

    public function isConfigured(): bool
    {
        return $this->phase !== CourseRegistrationPhase::CONFIGURATION_ERROR;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'phase' => $this->phase->value,
            'configured' => $this->isConfigured(),
            'student_registration_open' => $this->isStudentOpen(),
            'advisor_decision_open' => $this->isAdvisorDecisionOpen(),
            'academic_year_id' => $this->academicYearId,
            'semester_id' => $this->semesterId,
            'evaluated_at' => $this->evaluatedAt->toIso8601String(),
            'starts_at' => $this->startsAt?->toIso8601String(),
            'student_registration_ends_at' => $this->studentRegistrationEndsAt?->toIso8601String(),
            'advisor_approval_ends_at' => $this->advisorApprovalEndsAt?->toIso8601String(),
            'academic_calendar_event_id' => $this->academicCalendarEventId,
            'academic_calendar_event_version_id' => $this->academicCalendarEventVersionId,
            'reason_code' => $this->reasonCode,
            'legacy_deadline_fallback' => $this->legacyDeadlineFallback,
        ];
    }
}
