<?php

namespace App\Services;

use App\Exceptions\CourseOfferingClosureException;
use App\Exceptions\ExceptionalOpeningException;
use App\Exceptions\TeachingAssignmentException;
use App\Models\CourseOffering;
use App\Models\CourseOfferingExceptionRequest;
use App\Models\CourseOfferingExceptionReview;
use App\Models\CourseOfferingInstructor;
use App\Models\TeachingAssignmentRequest;
use App\Models\User;
use App\Support\ExceptionalOpeningWorkflow;
use App\Support\SemesterOfferingOpeningProof;
use App\Support\TeachingAssignmentWorkflow;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CourseOfferingOpeningService
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public function __construct(
        private CourseOfferingInstructorCoverageService $coverage,
        private CourseOfferingExceptionInvalidationService $exceptionInvalidation,
        private SemesterOfferingNormalOpenGate $semesterGovernance,
    ) {
    }

    /**
     * Normal closed → open transition. Coverage is an academic invariant:
     * no role, including Super Admin, bypasses it.
     */
    public function normalOpen(
        CourseOffering $offering,
        ?User $actor = null,
        ?SemesterOfferingOpeningProof $semesterProof = null,
    ): CourseOffering
    {
        return $this->applyThenGuardOpenCoverage($offering, static function (): void {}, true, $actor, $semesterProof);
    }

    /**
     * Apply Offering metadata inside the same locked opening transaction,
     * then ensure the Offering ends OPEN with complete coverage.
     *
     * @param  callable(CourseOffering): void  $mutate
     */
    public function applyThenNormalOpen(
        CourseOffering $offering,
        callable $mutate,
        ?User $actor = null,
        ?SemesterOfferingOpeningProof $semesterProof = null,
    ): CourseOffering
    {
        return $this->applyThenGuardOpenCoverage($offering, $mutate, true, $actor, $semesterProof);
    }

    /**
     * Apply Offering metadata inside one locked transaction.
     * If the Offering remains or becomes OPEN, coverage is enforced against
     * the FINAL Course whenever this request is a true open transition or
     * the coverage-driving course_id changed. Unchanged already-open
     * Offerings stay idempotent and are not retroactively rejected.
     *
     * @param  callable(CourseOffering): void  $mutate
     */
    public function applyThenGuardOpenCoverage(
        CourseOffering $offering,
        callable $mutate,
        bool $ensureOpen,
        ?User $actor = null,
        ?SemesterOfferingOpeningProof $semesterProof = null,
    ): CourseOffering {
        return $this->withLockedOffering($offering, function (CourseOffering $locked) use ($mutate, $ensureOpen, $actor, $semesterProof): CourseOffering {
            $originalCourseId = (int) $locked->course_id;
            $originalStatus = (string) $locked->status;

            $mutate($locked);
            $this->forgetCoverageGraph($locked);

            // Phase 7: generic mutation must never be a semantic OPEN → CLOSED.
            // Formal closure materializes inside CourseOfferingClosureWorkflowService
            // and must not call this function.
            if ($originalStatus === self::STATUS_OPEN
                && (string) $locked->status === self::STATUS_CLOSED) {
                throw CourseOfferingClosureException::workflowRequired();
            }

            return $this->finalizeLockedOpenInvariant(
                $locked,
                $originalCourseId,
                $originalStatus,
                $ensureOpen,
                $actor,
                $semesterProof,
            );
        });
    }

    /**
     * One-time CLOSED → OPEN from a locked, current, dual-approved
     * exceptional-opening request. Not a generic bypass: callers must
     * already hold the Offering, request, and both current-version review
     * rows inside a transaction. Coverage is intentionally not required.
     */
    public function openFromApprovedException(
        CourseOffering $lockedOffering,
        CourseOfferingExceptionRequest $lockedRequest,
        CourseOfferingExceptionReview $scientificReview,
        CourseOfferingExceptionReview $administrativeReview,
    ): CourseOffering {
        if (DB::transactionLevel() < 1) {
            throw ExceptionalOpeningException::transactionRequired();
        }

        if (! $lockedRequest->isCurrent()
            || $lockedRequest->isMaterialized()
            || (int) $lockedRequest->course_offering_id !== (int) $lockedOffering->course_offering_id
        ) {
            throw ExceptionalOpeningException::proofInvalid();
        }

        if ((string) $lockedOffering->status !== self::STATUS_CLOSED) {
            throw ExceptionalOpeningException::proofInvalid();
        }

        $this->assertNoPendingInstructorRemoval($lockedOffering);

        if (! $lockedRequest->identityMatches($lockedOffering)) {
            throw ExceptionalOpeningException::requestStale();
        }

        $version = (int) $lockedRequest->submission_version;
        $this->assertCurrentApprovedReview(
            $scientificReview,
            $lockedRequest,
            ExceptionalOpeningWorkflow::AUTHORITY_SCIENTIFIC,
            $version
        );
        $this->assertCurrentApprovedReview(
            $administrativeReview,
            $lockedRequest,
            ExceptionalOpeningWorkflow::AUTHORITY_ADMINISTRATIVE,
            $version
        );

        if ($lockedRequest->status !== ExceptionalOpeningWorkflow::STATUS_APPROVED) {
            $lockedRequest->status = ExceptionalOpeningWorkflow::STATUS_APPROVED;
            $lockedRequest->approved_at = $lockedRequest->approved_at ?? now();
        }

        $lockedOffering->status = self::STATUS_OPEN;
        $lockedOffering->save();

        $lockedRequest->materialized_at = now();
        $lockedRequest->save();

        return $lockedOffering;
    }

    private function assertCurrentApprovedReview(
        CourseOfferingExceptionReview $review,
        CourseOfferingExceptionRequest $request,
        string $authority,
        int $version,
    ): void {
        if ((int) $review->course_offering_exception_request_id !== (int) $request->course_offering_exception_request_id
            || (string) $review->review_authority !== $authority
            || (int) $review->submission_version !== $version
            || (string) $review->status !== ExceptionalOpeningWorkflow::REVIEW_APPROVED
        ) {
            throw ExceptionalOpeningException::proofInvalid();
        }
    }

    /**
     * Canonical lock order: course_offerings, then pending removal requests,
     * then course_offering_instructors. Never lock instructors before the offering.
     *
     * @param  callable(CourseOffering): CourseOffering  $then
     */
    private function withLockedOffering(CourseOffering $offering, callable $then): CourseOffering
    {
        return DB::transaction(function () use ($offering, $then): CourseOffering {
            $locked = CourseOffering::query()
                ->whereKey($offering->course_offering_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->lockPendingRemovalRequests($locked);

            CourseOfferingInstructor::query()
                ->where('course_offering_id', $locked->course_offering_id)
                ->lockForUpdate()
                ->get();

            return $then($locked);
        });
    }

    private function finalizeLockedOpenInvariant(
        CourseOffering $locked,
        int $originalCourseId,
        string $originalStatus,
        bool $ensureOpen,
        ?User $actor,
        ?SemesterOfferingOpeningProof $semesterProof,
    ): CourseOffering {
        $this->reloadCoverageGraph($locked);

        $courseIdentityChanged = (int) $locked->course_id !== $originalCourseId;

        if ($locked->status === self::STATUS_OPEN) {
            $unchangedOpen = $originalStatus === self::STATUS_OPEN && ! $courseIdentityChanged;
            if ($unchangedOpen) {
                return $locked;
            }

            if ($originalStatus === self::STATUS_CLOSED) {
                $this->semesterGovernance->authorize($locked, $semesterProof);
            }

            $this->coverage->assertCompleteForNormalOpening($locked);

            if ($originalStatus === self::STATUS_CLOSED) {
                if ($semesterProof !== null && $locked->academic_program_id !== null) {
                    $this->semesterGovernance->consume($locked, $semesterProof);
                }
                $this->exceptionInvalidation->supersedeCurrentForNormalOpen($locked, $actor);
            }

            return $locked;
        }

        if (! $ensureOpen) {
            return $locked;
        }

        if ($locked->status !== self::STATUS_CLOSED) {
            throw new ConflictHttpException('تعذّر تنفيذ العملية بسبب تغير حالة المادة. أعد تحميل البيانات وحاول مجددًا.');
        }

        $this->semesterGovernance->authorize($locked, $semesterProof);
        $this->assertNoPendingInstructorRemoval($locked);
        $this->coverage->assertCompleteForNormalOpening($locked);

        $locked->status = self::STATUS_OPEN;
        $locked->save();

        if ($semesterProof !== null && $locked->academic_program_id !== null) {
            $this->semesterGovernance->consume($locked, $semesterProof);
        }

        $this->exceptionInvalidation->supersedeCurrentForNormalOpen($locked, $actor);

        return $locked;
    }

    /**
     * A current unmaterialized instructor-removal request must not survive
     * CLOSED → OPEN. Guard both Phase 5 normal opening and Phase 6
     * exceptional opening. No-op when Phase 8 columns are not installed.
     */
    private function assertNoPendingInstructorRemoval(CourseOffering $lockedOffering): void
    {
        if (! TeachingAssignmentWorkflow::schemaReady()) {
            return;
        }

        $this->lockPendingRemovalRequests($lockedOffering);

        $pending = TeachingAssignmentRequest::query()
            ->where('course_offering_id', $lockedOffering->course_offering_id)
            ->where('action_type', TeachingAssignmentWorkflow::ACTION_REMOVE)
            ->where('current_slot', 1)
            ->whereIn('status', [
                TeachingAssignmentWorkflow::STATUS_SUBMITTED,
                TeachingAssignmentWorkflow::STATUS_RETURNED,
            ])
            ->exists();

        if ($pending) {
            throw TeachingAssignmentException::removalPending();
        }
    }

    private function lockPendingRemovalRequests(CourseOffering $lockedOffering): void
    {
        if (! TeachingAssignmentWorkflow::schemaReady()) {
            return;
        }

        TeachingAssignmentRequest::query()
            ->where('course_offering_id', $lockedOffering->course_offering_id)
            ->where('action_type', TeachingAssignmentWorkflow::ACTION_REMOVE)
            ->where('current_slot', 1)
            ->orderBy('teaching_assignment_request_id')
            ->lockForUpdate()
            ->get();
    }

    private function forgetCoverageGraph(CourseOffering $offering): void
    {
        $offering->unsetRelation('course');
        $offering->unsetRelation('facultyMember');
        $offering->unsetRelation('offeringInstructors');
    }

    private function reloadCoverageGraph(CourseOffering $offering): void
    {
        $this->forgetCoverageGraph($offering);
        $offering->load([
            'course',
            'facultyMember.employee.employeeStatus',
            'offeringInstructors' => fn ($instructors) => $instructors
                ->where('is_active', true)
                ->with('facultyMember.employee.employeeStatus'),
        ]);
    }
}
