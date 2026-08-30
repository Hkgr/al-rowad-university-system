<?php

namespace App\Services;

use App\Exceptions\SemesterOfferingGovernanceException;
use App\Models\CourseOffering;
use App\Models\ProgramCourse;
use App\Models\SemesterOfferingEvent;
use App\Support\SemesterOfferingGovernance;
use App\Support\SemesterOfferingOpeningProof;
use Illuminate\Support\Facades\DB;

/**
 * Server-derived gate for normal program-specific CLOSED -> OPEN transitions.
 * Exceptional opening deliberately does not call this collaborator.
 */
class SemesterOfferingNormalOpenGate
{
    public function authorize(CourseOffering $lockedOffering, ?SemesterOfferingOpeningProof $proof): void
    {
        if ($lockedOffering->academic_program_id === null) {
            return;
        }

        if (! SemesterOfferingGovernance::schemaReady()) {
            throw SemesterOfferingGovernanceException::schemaNotReady();
        }

        $programCourses = ProgramCourse::query()
            ->where('academic_program_id', $lockedOffering->academic_program_id)
            ->where('course_id', $lockedOffering->course_id)
            ->where('is_active', true)
            ->orderBy('program_course_id')
            ->lockForUpdate()
            ->get(['program_course_id', 'course_type']);

        if ($programCourses->count() !== 1) {
            throw SemesterOfferingGovernanceException::curriculumUnavailable();
        }

        if ($proof === null) {
            throw SemesterOfferingGovernanceException::approvalRequired();
        }

        $request = $proof->request;
        $review = $proof->review;
        $version = (int) $request->submission_version;

        if ($request->materialized_at !== null) {
            throw SemesterOfferingGovernanceException::materialized();
        }

        if (DB::transactionLevel() < 1
            || ! $request->exists
            || ! $review->exists
            || (int) $request->course_offering_id !== (int) $lockedOffering->course_offering_id
            || (int) $request->program_course_id !== (int) $programCourses->first()->program_course_id
            || strtolower((string) $request->course_type) !== strtolower((string) $programCourses->first()->course_type)
            || (string) $request->status !== SemesterOfferingGovernance::STATUS_APPROVED
            || ! $request->is_selected
            || (int) $review->semester_offering_request_id !== (int) $request->semester_offering_request_id
            || (int) $review->submission_version !== $version
            || (string) $review->status !== SemesterOfferingGovernance::REVIEW_APPROVED
        ) {
            throw SemesterOfferingGovernanceException::approvalRequired();
        }
    }

    public function consume(CourseOffering $lockedOffering, SemesterOfferingOpeningProof $proof): void
    {
        $this->authorize($lockedOffering, $proof);

        $request = $proof->request;
        $request->materialized_at = now();
        $request->save();

        SemesterOfferingEvent::query()->create([
            'semester_offering_request_id' => $request->semester_offering_request_id,
            'submission_version' => $request->submission_version,
            'event_type' => SemesterOfferingGovernance::EVENT_MATERIALIZED,
            'actor_user_id' => $proof->actorUserId,
            'occurred_at' => now(),
        ]);
    }
}
