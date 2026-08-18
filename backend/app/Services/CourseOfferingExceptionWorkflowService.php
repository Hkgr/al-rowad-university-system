<?php

namespace App\Services;

use App\Exceptions\ExceptionalOpeningException;
use App\Models\CourseOffering;
use App\Models\CourseOfferingExceptionEvent;
use App\Models\CourseOfferingExceptionRequest;
use App\Models\CourseOfferingExceptionReview;
use App\Models\User;
use App\Support\ExceptionalOpeningWorkflow;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CourseOfferingExceptionWorkflowService
{
    public function __construct(
        private TeachingAssignmentService $assignments,
        private DataScopeService $dataScope,
        private CourseOfferingInstructorCoverageService $coverage,
        private CourseOfferingOpeningService $opening,
    ) {
    }

    public function submit(User $user, CourseOffering $offering, string $reason): CourseOfferingExceptionRequest
    {
        $this->assertCanRequest($user);
        $trimmed = $this->requireReason($reason);

        return $this->finish($this->runLocked($offering->course_offering_id, function (CourseOffering $locked) use ($user, $trimmed): array {
            $this->assertOfferingInDeanScope($user, $locked);
            $this->assertOfferingClosed($locked);
            $this->assertCoverageIncomplete($locked);

            $current = $this->lockCurrentRequest((int) $locked->course_offering_id);
            if ($current === null) {
                return $this->created($this->createRequest($user, $locked, $trimmed));
            }

            if ($current->isMaterialized()) {
                $this->supersedeUnlocked(
                    $user,
                    $current,
                    ExceptionalOpeningWorkflow::SUPERSEDE_PRIOR_MATERIALIZATION,
                    ExceptionalOpeningWorkflow::EVENT_SUPERSEDED
                );

                return $this->created($this->createRequest($user, $locked, $trimmed));
            }

            if ($current->status === ExceptionalOpeningWorkflow::STATUS_RETURNED) {
                return $this->created($this->resubmitUnlocked($user, $current, $trimmed));
            }

            throw ExceptionalOpeningException::duplicateCurrent();
        }));
    }

    public function resubmit(User $user, CourseOfferingExceptionRequest $request, ?string $reason = null): CourseOfferingExceptionRequest
    {
        $this->assertCanRequest($user);
        $trimmed = $reason === null ? null : $this->requireReason($reason);

        return $this->finish($this->runLocked($request->course_offering_id, function (CourseOffering $locked) use ($user, $request, $trimmed): array {
            $current = $this->lockRequestById((int) $request->course_offering_exception_request_id);
            $this->assertOfferingInDeanScope($user, $locked);

            if (! $current->isCurrent() || $current->status === ExceptionalOpeningWorkflow::STATUS_SUPERSEDED) {
                throw ExceptionalOpeningException::notCurrent();
            }
            if ($current->isMaterialized()) {
                throw ExceptionalOpeningException::alreadyMaterialized();
            }
            if ($current->status !== ExceptionalOpeningWorkflow::STATUS_RETURNED) {
                throw ExceptionalOpeningException::notCurrent();
            }

            $this->assertOfferingClosed($locked);
            $this->assertCoverageIncomplete($locked);

            return $this->created($this->resubmitUnlocked($user, $current, $trimmed));
        }));
    }

    public function approveScientific(User $user, CourseOfferingExceptionRequest $request): CourseOfferingExceptionRequest
    {
        $this->assertScientificReviewer($user);

        return $this->decide($user, $request, ExceptionalOpeningWorkflow::AUTHORITY_SCIENTIFIC, ExceptionalOpeningWorkflow::REVIEW_APPROVED, null);
    }

    public function returnScientific(User $user, CourseOfferingExceptionRequest $request, string $reason): CourseOfferingExceptionRequest
    {
        $this->assertScientificReviewer($user);

        return $this->decide($user, $request, ExceptionalOpeningWorkflow::AUTHORITY_SCIENTIFIC, ExceptionalOpeningWorkflow::REVIEW_RETURNED, $reason);
    }

    public function approveAdministrative(User $user, CourseOfferingExceptionRequest $request): CourseOfferingExceptionRequest
    {
        $this->assertAdministrativeReviewer($user);

        return $this->decide($user, $request, ExceptionalOpeningWorkflow::AUTHORITY_ADMINISTRATIVE, ExceptionalOpeningWorkflow::REVIEW_APPROVED, null);
    }

    public function returnAdministrative(User $user, CourseOfferingExceptionRequest $request, string $reason): CourseOfferingExceptionRequest
    {
        $this->assertAdministrativeReviewer($user);

        return $this->decide($user, $request, ExceptionalOpeningWorkflow::AUTHORITY_ADMINISTRATIVE, ExceptionalOpeningWorkflow::REVIEW_RETURNED, $reason);
    }

    public function deanRequestsQuery(User $user)
    {
        $this->assertCanView($user);

        return CourseOfferingExceptionRequest::query()
            ->where('current_slot', 1)
            ->whereIn('course_offering_id', $this->scopedOfferingIdsQuery($user));
    }

    public function reviewQueueQuery(User $user, string $authority)
    {
        if ($authority === ExceptionalOpeningWorkflow::AUTHORITY_SCIENTIFIC) {
            $this->assertScientificReviewer($user);
        } else {
            $this->assertAdministrativeReviewer($user);
        }

        return CourseOfferingExceptionRequest::query()
            ->where('current_slot', 1)
            ->whereIn('course_offering_id', $this->scopedOfferingIdsQuery($user));
    }

    public function assertCanViewRequest(User $user, CourseOfferingExceptionRequest $request): void
    {
        $this->assertCanView($user);
        $offering = CourseOffering::query()->findOrFail($request->course_offering_id);
        if (! $this->dataScope->canAccessOffering($user, $offering)
            && ! $this->offeringInAccessibleColleges($user, $offering)) {
            throw ExceptionalOpeningException::offeringOutsideScope();
        }
    }

    public function requestDisplayRelations(): array
    {
        return [
            ...$this->requestListRelations(),
            'events.actor',
        ];
    }

    public function requestListRelations(): array
    {
        return [
            'courseOffering.course',
            'courseOffering.academicProgram.department.college',
            'courseOffering.department.college',
            'courseOffering.academicYear',
            'courseOffering.semester',
            ...array_map(
                static fn (string $relation): string => 'courseOffering.'.$relation,
                CourseOfferingInstructorCoverageService::eagerLoadRelations()
            ),
            'requester',
            'reviews.reviewer',
        ];
    }

    public function deanCardRelations(): array
    {
        return [
            'requester',
            'reviews.reviewer',
        ];
    }

    public function cardSummary(?CourseOfferingExceptionRequest $request): ?array
    {
        if ($request === null) {
            return null;
        }

        return [
            'course_offering_exception_request_id' => $request->course_offering_exception_request_id,
            'status' => $request->status,
            'submission_version' => $request->submission_version,
            'reason' => $request->reason,
            'submitted_at' => $request->submitted_at,
            'materialized_at' => $request->materialized_at,
            'requester' => $this->safeUser($request->relationLoaded('requester') ? $request->requester : null),
            'scientific_review' => $this->reviewSummary($request->scientificReview()),
            'administrative_review' => $this->reviewSummary($request->administrativeReview()),
        ];
    }

    /**
     * @param  callable(CourseOffering): array{request: CourseOfferingExceptionRequest, error: ?string}  $then
     * @return array{request: CourseOfferingExceptionRequest, error: ?string}
     */
    private function runLocked(int $offeringId, callable $then): array
    {
        return DB::transaction(function () use ($offeringId, $then): array {
            $locked = CourseOffering::query()
                ->whereKey($offeringId)
                ->lockForUpdate()
                ->firstOrFail();

            return $then($locked);
        });
    }

    /**
     * @param  array{request: CourseOfferingExceptionRequest, error: ?string}  $result
     */
    private function finish(array $result): CourseOfferingExceptionRequest
    {
        return match ($result['error']) {
            ExceptionalOpeningException::NORMAL_OPENING_AVAILABLE => throw ExceptionalOpeningException::normalOpeningAvailable(),
            ExceptionalOpeningException::REQUEST_STALE => throw ExceptionalOpeningException::requestStale(),
            ExceptionalOpeningException::SUPERSEDED_ALREADY_OPEN => throw ExceptionalOpeningException::supersededAlreadyOpen(),
            default => $result['request'],
        };
    }

    private function created(CourseOfferingExceptionRequest $request): array
    {
        return ['request' => $request, 'error' => null];
    }

    private function decide(
        User $user,
        CourseOfferingExceptionRequest $request,
        string $authority,
        string $decision,
        ?string $reason
    ): CourseOfferingExceptionRequest {
        return $this->finish($this->runLocked($request->course_offering_id, function (CourseOffering $locked) use ($user, $request, $authority, $decision, $reason): array {
            $current = $this->lockRequestById((int) $request->course_offering_exception_request_id);

            if (! $this->dataScope->canAccessOffering($user, $locked)
                && ! $this->offeringInAccessibleColleges($user, $locked)) {
                throw ExceptionalOpeningException::offeringOutsideScope();
            }

            if ($current->status === ExceptionalOpeningWorkflow::STATUS_SUPERSEDED || ! $current->isCurrent()) {
                throw ExceptionalOpeningException::superseded();
            }

            $scientific = $this->lockCurrentReview($current, ExceptionalOpeningWorkflow::AUTHORITY_SCIENTIFIC);
            $administrative = $this->lockCurrentReview($current, ExceptionalOpeningWorkflow::AUTHORITY_ADMINISTRATIVE);
            $own = $authority === ExceptionalOpeningWorkflow::AUTHORITY_SCIENTIFIC ? $scientific : $administrative;
            if ($own === null || $scientific === null || $administrative === null) {
                throw ExceptionalOpeningException::notCurrent();
            }

            if ($current->isMaterialized()) {
                if ($decision === ExceptionalOpeningWorkflow::REVIEW_APPROVED
                    && $own->status === ExceptionalOpeningWorkflow::REVIEW_APPROVED) {
                    return $this->created($this->loadRequest((int) $current->course_offering_exception_request_id));
                }

                throw ExceptionalOpeningException::alreadyMaterialized();
            }

            if ($current->status === ExceptionalOpeningWorkflow::STATUS_APPROVED && $own->status === ExceptionalOpeningWorkflow::REVIEW_APPROVED) {
                if ($decision === ExceptionalOpeningWorkflow::REVIEW_APPROVED) {
                    return $this->attemptMaterialize($user, $locked, $current, $scientific, $administrative);
                }

                throw ExceptionalOpeningException::reviewLocked();
            }

            if ($own->status === ExceptionalOpeningWorkflow::REVIEW_APPROVED) {
                if ($decision === ExceptionalOpeningWorkflow::REVIEW_APPROVED) {
                    return $this->attemptMaterialize($user, $locked, $current, $scientific, $administrative);
                }

                throw ExceptionalOpeningException::reviewLocked();
            }

            if ($own->status === ExceptionalOpeningWorkflow::REVIEW_RETURNED) {
                $trimmed = trim((string) $reason);
                if ($decision === ExceptionalOpeningWorkflow::REVIEW_RETURNED
                    && $trimmed !== ''
                    && trim((string) $own->notes) === $trimmed) {
                    return $this->created($this->loadRequest((int) $current->course_offering_exception_request_id));
                }

                throw ExceptionalOpeningException::reviewLocked();
            }

            if ($own->status !== ExceptionalOpeningWorkflow::REVIEW_PENDING) {
                throw ExceptionalOpeningException::reviewLocked();
            }

            if ($decision === ExceptionalOpeningWorkflow::REVIEW_RETURNED) {
                $trimmed = trim((string) $reason);
                if ($trimmed === '') {
                    throw ExceptionalOpeningException::returnReasonRequired();
                }
                $own->status = ExceptionalOpeningWorkflow::REVIEW_RETURNED;
                $own->notes = $trimmed;
                $own->reviewed_by_user_id = $user->user_id;
                $own->reviewed_at = now();
                $own->save();
                $this->recordEvent(
                    $current,
                    $authority === ExceptionalOpeningWorkflow::AUTHORITY_SCIENTIFIC
                        ? ExceptionalOpeningWorkflow::EVENT_SCIENTIFIC_RETURNED
                        : ExceptionalOpeningWorkflow::EVENT_ADMINISTRATIVE_RETURNED,
                    $user,
                    $trimmed
                );
            } else {
                $own->status = ExceptionalOpeningWorkflow::REVIEW_APPROVED;
                $own->notes = null;
                $own->reviewed_by_user_id = $user->user_id;
                $own->reviewed_at = now();
                $own->save();
                $this->recordEvent(
                    $current,
                    $authority === ExceptionalOpeningWorkflow::AUTHORITY_SCIENTIFIC
                        ? ExceptionalOpeningWorkflow::EVENT_SCIENTIFIC_APPROVED
                        : ExceptionalOpeningWorkflow::EVENT_ADMINISTRATIVE_APPROVED,
                    $user,
                    null
                );
            }

            $scientific->refresh();
            $administrative->refresh();

            if ($scientific->status === ExceptionalOpeningWorkflow::REVIEW_RETURNED
                || $administrative->status === ExceptionalOpeningWorkflow::REVIEW_RETURNED) {
                $current->status = ExceptionalOpeningWorkflow::STATUS_RETURNED;
                $current->approved_at = null;
                $current->save();

                return $this->created($this->loadRequest((int) $current->course_offering_exception_request_id));
            }

            if ($scientific->status === ExceptionalOpeningWorkflow::REVIEW_APPROVED
                && $administrative->status === ExceptionalOpeningWorkflow::REVIEW_APPROVED) {
                return $this->attemptMaterialize($user, $locked, $current, $scientific, $administrative);
            }

            $current->status = ExceptionalOpeningWorkflow::STATUS_SUBMITTED;
            $current->approved_at = null;
            $current->save();

            return $this->created($this->loadRequest((int) $current->course_offering_exception_request_id));
        }));
    }

    private function attemptMaterialize(
        User $user,
        CourseOffering $locked,
        CourseOfferingExceptionRequest $current,
        CourseOfferingExceptionReview $scientific,
        CourseOfferingExceptionReview $administrative,
    ): array {
        if ($current->isMaterialized()) {
            return $this->created($this->loadRequest((int) $current->course_offering_exception_request_id));
        }

        if (! $current->isCurrent()) {
            throw ExceptionalOpeningException::superseded();
        }

        if ((string) $locked->status === CourseOfferingOpeningService::STATUS_OPEN) {
            $this->supersedeUnlocked(
                $user,
                $current,
                ExceptionalOpeningWorkflow::SUPERSEDE_OFFERING_OPENED_NORMALLY,
                ExceptionalOpeningWorkflow::EVENT_SUPERSEDED_OFFERING_OPENED_NORMALLY
            );

            return [
                'request' => $this->loadRequest((int) $current->course_offering_exception_request_id),
                'error' => ExceptionalOpeningException::SUPERSEDED_ALREADY_OPEN,
            ];
        }

        if (! $current->identityMatches($locked)) {
            $this->supersedeUnlocked(
                $user,
                $current,
                ExceptionalOpeningWorkflow::SUPERSEDE_IDENTITY_STALE,
                ExceptionalOpeningWorkflow::EVENT_SUPERSEDED_IDENTITY_STALE
            );

            return [
                'request' => $this->loadRequest((int) $current->course_offering_exception_request_id),
                'error' => ExceptionalOpeningException::REQUEST_STALE,
            ];
        }

        $this->reloadCoverageGraph($locked);
        if ($this->coverage->isComplete($locked)) {
            $this->supersedeUnlocked(
                $user,
                $current,
                ExceptionalOpeningWorkflow::SUPERSEDE_NORMAL_OPENING_AVAILABLE,
                ExceptionalOpeningWorkflow::EVENT_SUPERSEDED_NORMAL_OPENING_AVAILABLE
            );

            return [
                'request' => $this->loadRequest((int) $current->course_offering_exception_request_id),
                'error' => ExceptionalOpeningException::NORMAL_OPENING_AVAILABLE,
            ];
        }

        if ((string) $locked->status !== CourseOfferingOpeningService::STATUS_CLOSED) {
            throw ExceptionalOpeningException::offeringNotClosed();
        }

        $current->status = ExceptionalOpeningWorkflow::STATUS_APPROVED;
        $current->approved_at = $current->approved_at ?? now();
        $current->save();

        $this->opening->openFromApprovedException($locked, $current, $scientific, $administrative);
        $this->recordEvent($current, ExceptionalOpeningWorkflow::EVENT_MATERIALIZED, $user, null);

        return $this->created($this->loadRequest((int) $current->course_offering_exception_request_id));
    }

    private function createRequest(User $user, CourseOffering $offering, string $reason): CourseOfferingExceptionRequest
    {
        try {
            $request = new CourseOfferingExceptionRequest([
                'course_offering_id' => $offering->course_offering_id,
                'requested_by_user_id' => $user->user_id,
                'reason' => $reason,
                'status' => ExceptionalOpeningWorkflow::STATUS_SUBMITTED,
                'submission_version' => 1,
                'current_slot' => 1,
                'snapshot_course_id' => $offering->course_id,
                'snapshot_academic_program_id' => $offering->academic_program_id,
                'snapshot_academic_year_id' => $offering->academic_year_id,
                'snapshot_semester_id' => $offering->semester_id,
                'snapshot_department_id' => $offering->department_id,
                'submitted_at' => now(),
            ]);
            $request->save();
        } catch (QueryException $exception) {
            if ($this->isDuplicateCurrent($exception)) {
                throw ExceptionalOpeningException::duplicateCurrent();
            }
            throw $exception;
        }

        $this->createPendingReviews($request, 1);
        $this->recordEvent($request, ExceptionalOpeningWorkflow::EVENT_SUBMITTED, $user, $reason);

        return $this->loadRequest((int) $request->course_offering_exception_request_id);
    }

    private function resubmitUnlocked(
        User $user,
        CourseOfferingExceptionRequest $request,
        ?string $reason
    ): CourseOfferingExceptionRequest {
        $nextVersion = (int) $request->submission_version + 1;
        if ($reason !== null) {
            $request->reason = $reason;
        }
        $request->submission_version = $nextVersion;
        $request->submitted_at = now();
        $request->status = ExceptionalOpeningWorkflow::STATUS_SUBMITTED;
        $request->approved_at = null;
        $request->save();

        $this->createPendingReviews($request, $nextVersion);
        $this->recordEvent($request, ExceptionalOpeningWorkflow::EVENT_RESUBMITTED, $user, $request->reason);

        return $this->loadRequest((int) $request->course_offering_exception_request_id);
    }

    private function supersedeUnlocked(
        User $user,
        CourseOfferingExceptionRequest $current,
        string $reasonCode,
        string $eventType
    ): void {
        $current->status = ExceptionalOpeningWorkflow::STATUS_SUPERSEDED;
        $current->current_slot = null;
        $current->superseded_at = now();
        $current->superseded_reason = $reasonCode;
        $current->save();
        $this->recordEvent($current, $eventType, $user, $reasonCode);
    }

    private function createPendingReviews(CourseOfferingExceptionRequest $request, int $version): void
    {
        foreach ([
            ExceptionalOpeningWorkflow::AUTHORITY_SCIENTIFIC,
            ExceptionalOpeningWorkflow::AUTHORITY_ADMINISTRATIVE,
        ] as $authority) {
            CourseOfferingExceptionReview::query()->create([
                'course_offering_exception_request_id' => $request->course_offering_exception_request_id,
                'submission_version' => $version,
                'review_authority' => $authority,
                'status' => ExceptionalOpeningWorkflow::REVIEW_PENDING,
            ]);
        }
    }

    private function recordEvent(
        CourseOfferingExceptionRequest $request,
        string $type,
        User $user,
        ?string $notes
    ): void {
        CourseOfferingExceptionEvent::query()->create([
            'course_offering_exception_request_id' => $request->course_offering_exception_request_id,
            'event_type' => $type,
            'actor_user_id' => $user->user_id,
            'submission_version' => $request->submission_version,
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }

    private function lockCurrentRequest(int $offeringId): ?CourseOfferingExceptionRequest
    {
        return CourseOfferingExceptionRequest::query()
            ->where('course_offering_id', $offeringId)
            ->where('current_slot', 1)
            ->lockForUpdate()
            ->first();
    }

    private function lockRequestById(int $id): CourseOfferingExceptionRequest
    {
        return CourseOfferingExceptionRequest::query()
            ->whereKey($id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockCurrentReview(
        CourseOfferingExceptionRequest $request,
        string $authority
    ): ?CourseOfferingExceptionReview {
        return CourseOfferingExceptionReview::query()
            ->where('course_offering_exception_request_id', $request->course_offering_exception_request_id)
            ->where('submission_version', (int) $request->submission_version)
            ->where('review_authority', $authority)
            ->lockForUpdate()
            ->first();
    }

    private function loadRequest(int $id): CourseOfferingExceptionRequest
    {
        return CourseOfferingExceptionRequest::query()
            ->with($this->requestDisplayRelations())
            ->findOrFail($id);
    }

    private function assertCanRequest(User $user): void
    {
        if (! $user->hasPermission(ExceptionalOpeningWorkflow::PERMISSION_REQUEST)) {
            throw ExceptionalOpeningException::requestForbidden();
        }
    }

    private function assertCanView(User $user): void
    {
        if (! $user->hasPermission(ExceptionalOpeningWorkflow::PERMISSION_VIEW)
            && ! $user->hasPermission(ExceptionalOpeningWorkflow::PERMISSION_REQUEST)
            && ! $user->hasPermission(ExceptionalOpeningWorkflow::PERMISSION_REVIEW_SCIENTIFIC)
            && ! $user->hasPermission(ExceptionalOpeningWorkflow::PERMISSION_REVIEW_ADMINISTRATIVE)) {
            throw ExceptionalOpeningException::offeringOutsideScope();
        }
    }

    private function assertScientificReviewer(User $user): void
    {
        if (! $user->hasPermission(ExceptionalOpeningWorkflow::PERMISSION_REVIEW_SCIENTIFIC)) {
            throw ExceptionalOpeningException::scientificReviewForbidden();
        }
    }

    private function assertAdministrativeReviewer(User $user): void
    {
        if (! $user->hasPermission(ExceptionalOpeningWorkflow::PERMISSION_REVIEW_ADMINISTRATIVE)) {
            throw ExceptionalOpeningException::administrativeReviewForbidden();
        }
    }

    private function assertOfferingInDeanScope(User $user, CourseOffering $offering): void
    {
        if (! $this->offeringInAccessibleColleges($user, $offering)) {
            throw ExceptionalOpeningException::offeringOutsideScope();
        }
    }

    private function assertOfferingClosed(CourseOffering $offering): void
    {
        if ((string) $offering->status !== CourseOfferingOpeningService::STATUS_CLOSED) {
            throw ExceptionalOpeningException::offeringNotClosed();
        }
    }

    private function assertCoverageIncomplete(CourseOffering $offering): void
    {
        $this->reloadCoverageGraph($offering);
        if ($this->coverage->isComplete($offering)) {
            throw ExceptionalOpeningException::notRequired();
        }
    }

    private function reloadCoverageGraph(CourseOffering $offering): void
    {
        $offering->unsetRelation('course');
        $offering->unsetRelation('facultyMember');
        $offering->unsetRelation('offeringInstructors');
        $offering->load(CourseOfferingInstructorCoverageService::eagerLoadRelations());
    }

    private function offeringInAccessibleColleges(User $user, CourseOffering $offering): bool
    {
        $collegeIds = $this->assignments->accessibleCollegeIdList($user);
        if ($collegeIds === []) {
            return $this->dataScope->canAccessOffering($user, $offering);
        }

        return CourseOffering::query()
            ->whereKey($offering->course_offering_id)
            ->whereIn('course_offering_id', $this->assignments->offeringsInAccessibleCollegesQuery($collegeIds))
            ->exists();
    }

    private function scopedOfferingIdsQuery(User $user)
    {
        $query = CourseOffering::query()->select('course_offerings.course_offering_id');
        $this->dataScope->scopeOfferings($query, $user);
        $collegeIds = $this->assignments->accessibleCollegeIdList($user);
        if ($collegeIds === []) {
            return $query;
        }

        return $query->whereIn(
            'course_offerings.course_offering_id',
            $this->assignments->offeringsInAccessibleCollegesQuery($collegeIds)
        );
    }

    private function requireReason(string $reason): string
    {
        $trimmed = trim($reason);
        if ($trimmed === '') {
            throw ExceptionalOpeningException::reasonRequired();
        }

        return $trimmed;
    }

    private function isDuplicateCurrent(QueryException $exception): bool
    {
        $errorCode = (int) ($exception->errorInfo[1] ?? 0);

        return $errorCode === 1062
            || str_contains($exception->getMessage(), 'uq_coer_current_slot');
    }

    private function reviewSummary(?CourseOfferingExceptionReview $review): ?array
    {
        if ($review === null) {
            return null;
        }

        return [
            'review_authority' => $review->review_authority,
            'status' => $review->status,
            'notes' => $review->notes,
            'reviewed_at' => $review->reviewed_at,
            'reviewer' => $this->safeUser($review->relationLoaded('reviewer') ? $review->reviewer : null),
        ];
    }

    private function safeUser(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'user_id' => $user->user_id,
            'username' => $user->username,
        ];
    }
}
