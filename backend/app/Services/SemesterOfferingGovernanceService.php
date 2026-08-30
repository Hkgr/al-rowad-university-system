<?php

namespace App\Services;

use App\Exceptions\SemesterOfferingGovernanceException;
use App\Models\CourseOffering;
use App\Models\CourseOfferingInstructor;
use App\Models\ProgramCourse;
use App\Models\SemesterOfferingEvent;
use App\Models\SemesterOfferingRequest;
use App\Models\SemesterOfferingReview;
use App\Models\TeachingAssignmentRequest;
use App\Models\User;
use App\Support\SemesterOfferingGovernance;
use App\Support\SemesterOfferingOpeningProof;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SemesterOfferingGovernanceService
{
    public function __construct(
        private DataScopeService $scope,
        private CourseOfferingInstructorCoverageService $coverage,
        private CourseOfferingOpeningService $opening,
    ) {
    }

    public function prepareDraft(
        User $actor,
        CourseOffering $offering,
        ProgramCourse $programCourse,
        ?int $minimumEnrollment,
    ): SemesterOfferingRequest {
        $this->assertDeanManage($actor, (int) $programCourse->academic_program_id);
        $this->assertSchemaReady();

        return DB::transaction(function () use ($actor, $offering, $programCourse, $minimumEnrollment): SemesterOfferingRequest {
            $lockedOffering = $this->lockOffering((int) $offering->course_offering_id);
            $request = SemesterOfferingRequest::query()
                ->where('course_offering_id', $lockedOffering->course_offering_id)
                ->lockForUpdate()
                ->first();
            $lockedProgramCourse = ProgramCourse::query()
                ->whereKey($programCourse->program_course_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCurrentCurriculumIdentity($lockedOffering, $lockedProgramCourse, true);
            if ((string) $lockedOffering->status === CourseOfferingOpeningService::STATUS_OPEN) {
                throw SemesterOfferingGovernanceException::invalidState();
            }

            $this->validateProposal($lockedOffering, $lockedProgramCourse, true, $minimumEnrollment);

            if ($request === null) {
                $request = SemesterOfferingRequest::query()->create([
                    'course_offering_id' => $lockedOffering->course_offering_id,
                    'program_course_id' => $lockedProgramCourse->program_course_id,
                    'course_type' => $lockedProgramCourse->course_type,
                    'is_selected' => true,
                    'minimum_enrollment' => $minimumEnrollment,
                    'status' => SemesterOfferingGovernance::STATUS_DRAFT,
                    'submission_version' => 0,
                    'created_by_user_id' => $actor->user_id,
                ]);
                $this->event($request, $actor, SemesterOfferingGovernance::EVENT_PREPARED);

                return $request->fresh();
            }

            if (! in_array((string) $request->status, [
                SemesterOfferingGovernance::STATUS_DRAFT,
                SemesterOfferingGovernance::STATUS_RETURNED,
            ], true) || $request->materialized_at !== null) {
                throw SemesterOfferingGovernanceException::invalidState();
            }

            $request->fill([
                'program_course_id' => $lockedProgramCourse->program_course_id,
                'course_type' => $lockedProgramCourse->course_type,
                'is_selected' => true,
                'minimum_enrollment' => $minimumEnrollment,
            ])->save();
            $this->event($request, $actor, SemesterOfferingGovernance::EVENT_UPDATED);

            return $request->fresh();
        });
    }

    public function updateProposal(User $actor, CourseOffering $offering, array $payload): SemesterOfferingRequest
    {
        $this->assertSchemaReady();

        return DB::transaction(function () use ($actor, $offering, $payload): SemesterOfferingRequest {
            $lockedOffering = $this->lockOffering((int) $offering->course_offering_id);
            $request = $this->lockRequestForOffering($lockedOffering);
            $programCourse = ProgramCourse::query()->whereKey($request->program_course_id)->lockForUpdate()->firstOrFail();
            $this->assertDeanManage($actor, (int) $programCourse->academic_program_id);

            if (! in_array((string) $request->status, [SemesterOfferingGovernance::STATUS_DRAFT, SemesterOfferingGovernance::STATUS_RETURNED], true)
                || $request->materialized_at !== null) {
                throw SemesterOfferingGovernanceException::invalidState();
            }

            $selected = array_key_exists('is_selected', $payload) ? (bool) $payload['is_selected'] : (bool) $request->is_selected;
            $minimum = array_key_exists('minimum_enrollment', $payload)
                ? ($payload['minimum_enrollment'] === null ? null : (int) $payload['minimum_enrollment'])
                : $request->minimum_enrollment;
            $this->assertCurrentCurriculumIdentity($lockedOffering, $programCourse, true);
            $this->validateProposal($lockedOffering, $programCourse, $selected, $minimum);

            // Draft/returned proposals follow the current curriculum; the value
            // becomes an immutable submitted-version snapshot on submit.
            $request->course_type = $programCourse->course_type;
            $request->is_selected = $selected;
            $request->minimum_enrollment = $selected ? $minimum : null;
            $request->save();
            $this->event(
                $request,
                $actor,
                $selected ? SemesterOfferingGovernance::EVENT_UPDATED : SemesterOfferingGovernance::EVENT_DESELECTED,
            );

            return $this->loadRequest($request);
        });
    }

    public function submit(User $actor, CourseOffering $offering): SemesterOfferingRequest
    {
        $this->assertSchemaReady();

        return DB::transaction(function () use ($actor, $offering): SemesterOfferingRequest {
            $lockedOffering = $this->lockOffering((int) $offering->course_offering_id);
            $request = $this->lockRequestForOffering($lockedOffering);
            $programCourse = ProgramCourse::query()->whereKey($request->program_course_id)->lockForUpdate()->firstOrFail();
            $this->assertDeanManage($actor, (int) $programCourse->academic_program_id);

            if (! in_array((string) $request->status, [SemesterOfferingGovernance::STATUS_DRAFT, SemesterOfferingGovernance::STATUS_RETURNED], true)
                || $request->materialized_at !== null
                || ! $request->is_selected
                || (string) $lockedOffering->status !== CourseOfferingOpeningService::STATUS_CLOSED) {
                throw SemesterOfferingGovernanceException::invalidState();
            }

            $this->assertCurrentCurriculumIdentity($lockedOffering, $programCourse, true);
            $this->validateProposal($lockedOffering, $programCourse, (bool) $request->is_selected, $request->minimum_enrollment);
            $this->lockCoverageGraph($lockedOffering);
            $this->coverage->assertCompleteForNormalOpening($lockedOffering);

            $resubmission = (int) $request->submission_version > 0;
            $request->submission_version = (int) $request->submission_version + 1;
            $request->status = SemesterOfferingGovernance::STATUS_SUBMITTED;
            $request->submitted_by_user_id = $actor->user_id;
            $request->submitted_at = now();
            $request->approved_at = null;
            $request->save();

            SemesterOfferingReview::query()->create([
                'semester_offering_request_id' => $request->semester_offering_request_id,
                'submission_version' => $request->submission_version,
                'status' => SemesterOfferingGovernance::REVIEW_PENDING,
            ]);
            $this->event(
                $request,
                $actor,
                $resubmission ? SemesterOfferingGovernance::EVENT_RESUBMITTED : SemesterOfferingGovernance::EVENT_SUBMITTED,
            );

            return $this->loadRequest($request);
        });
    }

    public function approve(User $actor, SemesterOfferingRequest $routeRequest): SemesterOfferingRequest
    {
        $this->assertScientificReview($actor);
        $this->assertSchemaReady();

        return DB::transaction(function () use ($actor, $routeRequest): SemesterOfferingRequest {
            // Canonical order: CourseOffering before request/review and assignment locks.
            $offeringId = (int) SemesterOfferingRequest::query()
                ->whereKey($routeRequest->semester_offering_request_id)
                ->value('course_offering_id');
            $lockedOffering = $this->lockOffering($offeringId);
            $request = SemesterOfferingRequest::query()
                ->whereKey($routeRequest->semester_offering_request_id)
                ->lockForUpdate()
                ->firstOrFail();
            $review = $this->lockCurrentReview($request);

            if ((string) $request->status !== SemesterOfferingGovernance::STATUS_SUBMITTED
                || (string) $review->status !== SemesterOfferingGovernance::REVIEW_PENDING
                || $request->materialized_at !== null
                || (string) $lockedOffering->status !== CourseOfferingOpeningService::STATUS_CLOSED) {
                throw SemesterOfferingGovernanceException::invalidState();
            }

            $programCourse = ProgramCourse::query()
                ->whereKey($request->program_course_id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCurrentCurriculumIdentity($lockedOffering, $programCourse, true);
            if (strtolower((string) $programCourse->course_type) !== strtolower((string) $request->course_type)) {
                throw SemesterOfferingGovernanceException::proposalStale();
            }
            $this->validateProposal($lockedOffering, $programCourse, (bool) $request->is_selected, $request->minimum_enrollment);

            $review->status = SemesterOfferingGovernance::REVIEW_APPROVED;
            $review->reviewed_by_user_id = $actor->user_id;
            $review->reviewed_at = now();
            $review->reason = null;
            $review->save();

            $request->status = SemesterOfferingGovernance::STATUS_APPROVED;
            $request->approved_at = now();
            $request->save();
            $this->event($request, $actor, SemesterOfferingGovernance::EVENT_APPROVED);

            $proof = new SemesterOfferingOpeningProof($request, $review, (int) $actor->user_id);
            $this->opening->normalOpen($lockedOffering, $actor, $proof);

            return $this->loadRequest($request->fresh());
        });
    }

    public function returnForEditing(User $actor, SemesterOfferingRequest $routeRequest, string $reason): SemesterOfferingRequest
    {
        $this->assertScientificReview($actor);
        $this->assertSchemaReady();
        $reason = trim($reason);
        if ($reason === '') {
            throw SemesterOfferingGovernanceException::returnReasonRequired();
        }

        return DB::transaction(function () use ($actor, $routeRequest, $reason): SemesterOfferingRequest {
            $offeringId = (int) SemesterOfferingRequest::query()
                ->whereKey($routeRequest->semester_offering_request_id)
                ->value('course_offering_id');
            $this->lockOffering($offeringId);
            $request = SemesterOfferingRequest::query()->whereKey($routeRequest->semester_offering_request_id)->lockForUpdate()->firstOrFail();
            $review = $this->lockCurrentReview($request);

            if ((string) $request->status !== SemesterOfferingGovernance::STATUS_SUBMITTED
                || (string) $review->status !== SemesterOfferingGovernance::REVIEW_PENDING
                || $request->materialized_at !== null) {
                throw SemesterOfferingGovernanceException::invalidState();
            }

            $review->status = SemesterOfferingGovernance::REVIEW_RETURNED;
            $review->reviewed_by_user_id = $actor->user_id;
            $review->reviewed_at = now();
            $review->reason = $reason;
            $review->save();
            $request->status = SemesterOfferingGovernance::STATUS_RETURNED;
            $request->save();
            $this->event($request, $actor, SemesterOfferingGovernance::EVENT_RETURNED, $reason);

            return $this->loadRequest($request);
        });
    }

    public function reviewQueue(User $actor): Builder
    {
        $this->assertScientificReview($actor, SemesterOfferingGovernance::PERMISSION_VIEW);
        $this->assertSchemaReady();

        return SemesterOfferingRequest::query()->with($this->displayRelations());
    }

    public function show(User $actor, SemesterOfferingRequest $request): SemesterOfferingRequest
    {
        $this->assertScientificReview($actor, SemesterOfferingGovernance::PERMISSION_VIEW);
        $this->assertSchemaReady();

        return $this->loadRequest($request);
    }

    public function summary(?SemesterOfferingRequest $request): ?array
    {
        if ($request === null) {
            return null;
        }

        $latestReview = $request->relationLoaded('reviews')
            ? $request->reviews->sortByDesc('submission_version')->first()
            : $request->reviews()->orderByDesc('submission_version')->first();

        return [
            'semester_offering_request_id' => (int) $request->semester_offering_request_id,
            'status' => (string) $request->status,
            'is_selected' => (bool) $request->is_selected,
            'course_type' => (string) $request->course_type,
            'minimum_enrollment' => $request->minimum_enrollment,
            'submission_version' => (int) $request->submission_version,
            'submitted_at' => $request->submitted_at?->toISOString(),
            'approved_at' => $request->approved_at?->toISOString(),
            'materialized_at' => $request->materialized_at?->toISOString(),
            'return_note' => (string) $latestReview?->status === SemesterOfferingGovernance::REVIEW_RETURNED
                ? $latestReview?->reason
                : null,
        ];
    }

    public function payload(SemesterOfferingRequest $request): array
    {
        $request = $this->loadRequest($request);
        $offering = $request->courseOffering;

        return [
            ...$this->summary($request),
            'course_offering' => [
                'course_offering_id' => (int) $offering->course_offering_id,
                'status' => $offering->status,
                'course' => $offering->course,
                'academic_year' => $offering->academicYear,
                'semester' => $offering->semester,
                'academic_program' => $offering->academicProgram,
                'college' => $offering->academicProgram?->department?->college,
                'instructor_coverage' => $this->coverage->describe($offering),
            ],
            'submitted_by' => $request->submittedBy,
            'reviews' => $request->reviews->map(fn (SemesterOfferingReview $review): array => [
                'submission_version' => (int) $review->submission_version,
                'status' => $review->status,
                'reason' => $review->reason,
                'reviewed_at' => $review->reviewed_at?->toISOString(),
                'reviewed_by' => $review->reviewedBy,
            ])->values()->all(),
            'events' => $request->events->map(fn (SemesterOfferingEvent $event): array => [
                'event_type' => $event->event_type,
                'submission_version' => (int) $event->submission_version,
                'note' => $event->note,
                'occurred_at' => $event->occurred_at?->toISOString(),
            ])->values()->all(),
        ];
    }

    private function validateProposal(CourseOffering $offering, ProgramCourse $programCourse, bool $selected, ?int $minimum): void
    {
        $offering->loadMissing('semester');
        $semesterCode = (string) $offering->semester?->semester_code;
        $courseType = strtolower((string) $programCourse->course_type);
        $regularMandatory = in_array($semesterCode, ['first', 'second'], true) && $courseType === 'mandatory';

        if ($regularMandatory && ! $selected) {
            throw SemesterOfferingGovernanceException::mandatorySelectionRequired();
        }

        if ($regularMandatory && $minimum !== null) {
            throw SemesterOfferingGovernanceException::minimumEnrollmentNotAllowed();
        }

        $minimumRequired = $selected && ($semesterCode === 'summer' || $courseType === 'elective');
        if ($minimumRequired && ($minimum === null || $minimum < 1)) {
            throw SemesterOfferingGovernanceException::minimumEnrollmentRequired();
        }

        if ($selected && ! $regularMandatory && $minimum !== null && $minimum < 1) {
            throw SemesterOfferingGovernanceException::minimumEnrollmentRequired();
        }
    }

    private function assertCurrentCurriculumIdentity(
        CourseOffering $offering,
        ProgramCourse $programCourse,
        bool $lockMatchingRows = false,
    ): void
    {
        if (! $programCourse->is_active
            || (int) $programCourse->academic_program_id !== (int) $offering->academic_program_id
            || (int) $programCourse->course_id !== (int) $offering->course_id) {
            throw SemesterOfferingGovernanceException::curriculumUnavailable();
        }

        $query = ProgramCourse::query()
            ->where('academic_program_id', $offering->academic_program_id)
            ->where('course_id', $offering->course_id)
            ->where('is_active', true)
            ->orderBy('program_course_id');
        if ($lockMatchingRows) {
            $query->lockForUpdate();
        }
        $matches = $query->get(['program_course_id']);
        if ($matches->count() !== 1
            || (int) $matches->first()->program_course_id !== (int) $programCourse->program_course_id) {
            throw SemesterOfferingGovernanceException::curriculumUnavailable();
        }
    }

    private function lockCoverageGraph(CourseOffering $offering): void
    {
        TeachingAssignmentRequest::query()
            ->where('course_offering_id', $offering->course_offering_id)
            ->where('current_slot', 1)
            ->orderBy('teaching_assignment_request_id')
            ->lockForUpdate()
            ->get();
        CourseOfferingInstructor::query()
            ->where('course_offering_id', $offering->course_offering_id)
            ->orderBy('course_offering_instructor_id')
            ->lockForUpdate()
            ->get();
        $offering->unsetRelation('course');
        $offering->unsetRelation('facultyMember');
        $offering->unsetRelation('offeringInstructors');
        $offering->load(CourseOfferingInstructorCoverageService::eagerLoadRelations());
    }

    private function lockOffering(int $offeringId): CourseOffering
    {
        return CourseOffering::query()->whereKey($offeringId)->lockForUpdate()->firstOrFail();
    }

    private function lockRequestForOffering(CourseOffering $offering): SemesterOfferingRequest
    {
        $request = SemesterOfferingRequest::query()
            ->where('course_offering_id', $offering->course_offering_id)
            ->lockForUpdate()
            ->first();
        if ($request === null) {
            throw SemesterOfferingGovernanceException::approvalRequired();
        }

        return $request;
    }

    private function lockCurrentReview(SemesterOfferingRequest $request): SemesterOfferingReview
    {
        return SemesterOfferingReview::query()
            ->where('semester_offering_request_id', $request->semester_offering_request_id)
            ->where('submission_version', $request->submission_version)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function event(SemesterOfferingRequest $request, User $actor, string $type, ?string $note = null): void
    {
        SemesterOfferingEvent::query()->create([
            'semester_offering_request_id' => $request->semester_offering_request_id,
            'submission_version' => $request->submission_version,
            'event_type' => $type,
            'actor_user_id' => $actor->user_id,
            'note' => $note,
            'occurred_at' => now(),
        ]);
    }

    private function assertSchemaReady(): void
    {
        if (! SemesterOfferingGovernance::schemaReady()) {
            throw SemesterOfferingGovernanceException::schemaNotReady();
        }
    }

    private function assertDeanManage(User $actor, int $programId): void
    {
        if (! $actor->isDean()
            || ! $actor->effectivePermissions()->contains(SemesterOfferingGovernance::PERMISSION_MANAGE)
            || ! $this->scope->canAccessProgram($actor, $programId)) {
            throw SemesterOfferingGovernanceException::forbidden();
        }
    }

    public function assertDeanView(User $actor): void
    {
        if (! $actor->isDean()
            || ! $actor->effectivePermissions()->contains(SemesterOfferingGovernance::PERMISSION_VIEW)) {
            throw SemesterOfferingGovernanceException::forbidden();
        }
    }

    private function assertScientificReview(User $actor, string $permission = SemesterOfferingGovernance::PERMISSION_REVIEW_SCIENTIFIC): void
    {
        if (! $actor->isScientificVicePresident()
            || ! $actor->effectivePermissions()->contains($permission)
            || ! $this->scope->hasActualUniversityScope($actor)) {
            throw SemesterOfferingGovernanceException::forbidden();
        }
    }

    private function loadRequest(SemesterOfferingRequest $request): SemesterOfferingRequest
    {
        return $request->loadMissing($this->displayRelations());
    }

    private function displayRelations(): array
    {
        return [
            'programCourse', 'submittedBy', 'reviews.reviewedBy', 'events',
            'courseOffering.course', 'courseOffering.academicYear', 'courseOffering.semester',
            'courseOffering.academicProgram.department.college',
            ...array_map(static fn (string $relation): string => 'courseOffering.'.$relation, CourseOfferingInstructorCoverageService::eagerLoadRelations()),
        ];
    }
}
