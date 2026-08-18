<?php

namespace App\Services;

use App\Exceptions\ExceptionalOpeningException;
use App\Models\CourseOffering;
use App\Models\CourseOfferingExceptionRequest;
use App\Models\CourseOfferingExceptionReview;
use App\Models\CourseOfferingInstructor;
use App\Models\User;
use App\Support\ExceptionalOpeningWorkflow;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CourseOfferingOpeningService
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public function __construct(private CourseOfferingInstructorCoverageService $coverage)
    {
    }

    /**
     * Normal closed → open transition. Coverage is an academic invariant:
     * no role, including Super Admin, bypasses it.
     */
    public function normalOpen(CourseOffering $offering, ?User $actor = null): CourseOffering
    {
        return $this->applyThenGuardOpenCoverage($offering, static function (): void {}, true, $actor);
    }

    /**
     * Apply Offering metadata inside the same locked opening transaction,
     * then ensure the Offering ends OPEN with complete coverage.
     *
     * @param  callable(CourseOffering): void  $mutate
     */
    public function applyThenNormalOpen(CourseOffering $offering, callable $mutate, ?User $actor = null): CourseOffering
    {
        return $this->applyThenGuardOpenCoverage($offering, $mutate, true, $actor);
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
    ): CourseOffering {
        return $this->withLockedOffering($offering, function (CourseOffering $locked) use ($mutate, $ensureOpen): CourseOffering {
            $originalCourseId = (int) $locked->course_id;
            $originalStatus = (string) $locked->status;

            $mutate($locked);
            $this->forgetCoverageGraph($locked);

            return $this->finalizeLockedOpenInvariant(
                $locked,
                $originalCourseId,
                $originalStatus,
                $ensureOpen,
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
     * @param  callable(CourseOffering): CourseOffering  $then
     */
    private function withLockedOffering(CourseOffering $offering, callable $then): CourseOffering
    {
        return DB::transaction(function () use ($offering, $then): CourseOffering {
            $locked = CourseOffering::query()
                ->whereKey($offering->course_offering_id)
                ->lockForUpdate()
                ->firstOrFail();

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
    ): CourseOffering {
        $this->reloadCoverageGraph($locked);

        $courseIdentityChanged = (int) $locked->course_id !== $originalCourseId;

        if ($locked->status === self::STATUS_OPEN) {
            $unchangedOpen = $originalStatus === self::STATUS_OPEN && ! $courseIdentityChanged;
            if ($unchangedOpen) {
                return $locked;
            }

            $this->coverage->assertCompleteForNormalOpening($locked);

            return $locked;
        }

        if (! $ensureOpen) {
            return $locked;
        }

        if ($locked->status !== self::STATUS_CLOSED) {
            throw new ConflictHttpException('تعذّر تنفيذ العملية بسبب تغير حالة المادة. أعد تحميل البيانات وحاول مجددًا.');
        }

        $this->coverage->assertCompleteForNormalOpening($locked);

        $locked->status = self::STATUS_OPEN;
        $locked->save();

        return $locked;
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
