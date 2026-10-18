<?php

namespace App\Services;

use App\Models\CourseOffering;
use App\Models\CourseOfferingExceptionEvent;
use App\Models\CourseOfferingExceptionRequest;
use App\Models\User;
use App\Support\ExceptionalOpeningWorkflow;
use Illuminate\Support\Facades\Schema;

/**
 * Invalidates unmaterialized exceptional-opening requests without depending
 * on CourseOfferingOpeningService (avoids a circular service graph).
 */
class CourseOfferingExceptionInvalidationService
{
    public const REQUESTS_TABLE = 'course_offering_exception_requests';

    public const EVENTS_TABLE = 'course_offering_exception_events';

    /**
     * Consume the current unmaterialized exceptional request for a true
     * Phase 5 CLOSED → OPEN, inside the caller's already-open transaction
     * that holds the Offering row lock.
     *
     * No-op when Phase 6 tables have not been applied yet so pre-Phase-6
     * normal opening cannot 500.
     */
    public function supersedeCurrentForNormalOpen(CourseOffering $lockedOffering, ?User $actor): void
    {
        if (! $this->workflowTablesPresent()) {
            return;
        }

        $current = CourseOfferingExceptionRequest::query()
            ->where('course_offering_id', $lockedOffering->course_offering_id)
            ->where('current_slot', 1)
            ->whereNull('materialized_at')
            ->lockForUpdate()
            ->first();

        if ($current === null) {
            return;
        }

        $this->markSuperseded(
            $current,
            $actor,
            ExceptionalOpeningWorkflow::SUPERSEDE_OFFERING_OPENED_NORMALLY,
            ExceptionalOpeningWorkflow::EVENT_SUPERSEDED_OFFERING_OPENED_NORMALLY
        );
    }

    public function markSuperseded(
        CourseOfferingExceptionRequest $current,
        ?User $actor,
        string $reasonCode,
        string $eventType,
    ): void {
        if ($current->status === ExceptionalOpeningWorkflow::STATUS_SUPERSEDED
            && $current->current_slot === null) {
            return;
        }

        $current->status = ExceptionalOpeningWorkflow::STATUS_SUPERSEDED;
        $current->current_slot = null;
        $current->superseded_at = now();
        $current->superseded_reason = $reasonCode;
        $current->save();

        CourseOfferingExceptionEvent::query()->create([
            'course_offering_exception_request_id' => $current->course_offering_exception_request_id,
            'event_type' => $eventType,
            'actor_user_id' => $actor?->user_id,
            'submission_version' => $current->submission_version,
            'notes' => $reasonCode,
            'created_at' => now(),
        ]);
    }

    public function workflowTablesPresent(): bool
    {
        return Schema::hasTable(self::REQUESTS_TABLE)
            && Schema::hasTable(self::EVENTS_TABLE);
    }
}
