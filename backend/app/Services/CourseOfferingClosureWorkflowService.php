<?php

namespace App\Services;

use App\Exceptions\CourseOfferingClosureException;
use App\Models\CourseOffering;
use App\Models\CourseOfferingClosureEvent;
use App\Models\CourseOfferingClosureRequest;
use App\Models\CourseOfferingClosureReview;
use App\Models\User;
use App\Support\CourseOfferingClosureWorkflow;
use App\Support\SemesterRegistrationPhase6;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CourseOfferingClosureWorkflowService
{
    public function __construct(
        private TeachingAssignmentService $assignments,
        private DataScopeService $dataScope,
        private MinimumEnrollmentCancellationMaterializer $minimumCancellation,
    ) {
    }

    public function createFromMinimumEnrollmentCancellationWithinTransaction(User $scientific, \App\Models\CourseOfferingMinimumEnrollmentReview $review): CourseOfferingClosureRequest
    {
        if (DB::transactionLevel() < 1 || $review->status !== 'dean_recommended' || $review->scientific_decision !== 'cancel' || $review->dean_user_id === null || $review->scientific_user_id !== $scientific->user_id) {
            throw CourseOfferingClosureException::notCurrent();
        }
        $offering=CourseOffering::query()->whereKey($review->course_offering_id)->lockForUpdate()->firstOrFail();
        if ($offering->status !== CourseOfferingOpeningService::STATUS_OPEN || $this->lockCurrentRequest((int)$offering->getKey()) !== null) {
            throw \App\Exceptions\SemesterRegistrationPhase6Exception::fail('minimum_enrollment_closure_conflict','An incompatible closure request already exists.');
        }
        $dean=User::query()->findOrFail($review->dean_user_id);
        $request=$this->createRequest($dean,$offering,'Minimum enrollment cancellation: '.$review->dean_notes);
        $scientificReview=$this->lockCurrentReview($request,CourseOfferingClosureWorkflow::AUTHORITY_SCIENTIFIC);
        $scientificReview->update(['status'=>CourseOfferingClosureWorkflow::REVIEW_APPROVED,'reviewed_by_user_id'=>$scientific->user_id,'reviewed_at'=>$review->scientific_decided_at,'reason'=>null]);
        $this->recordEvent($request,CourseOfferingClosureWorkflow::EVENT_SCIENTIFIC_APPROVED,$scientific,$review->scientific_notes);
        return $this->loadRequest((int)$request->getKey());
    }

    public function submit(User $user, CourseOffering $offering, string $reason): CourseOfferingClosureRequest
    {
        $this->assertCanRequest($user);
        $trimmed = $this->requireReason($reason);

        return $this->finish($this->runLocked($offering->course_offering_id, function (CourseOffering $locked) use ($user, $trimmed): array {
            $this->assertOfferingInDeanScope($user, $locked);

            $current = $this->lockCurrentRequest((int) $locked->course_offering_id);
            if ($current !== null && ! $current->identityMatches($locked)) {
                $this->supersedeUnlocked(
                    $user,
                    $current,
                    CourseOfferingClosureWorkflow::SUPERSEDE_IDENTITY_CHANGED,
                    CourseOfferingClosureWorkflow::EVENT_SUPERSEDED_IDENTITY_CHANGED
                );
                $current = null;
            }

            $this->assertOfferingOpenForClosure($locked);

            if ($current === null) {
                return $this->created($this->createRequest($user, $locked, $trimmed));
            }

            if ($current->isMaterialized()) {
                $this->supersedeUnlocked(
                    $user,
                    $current,
                    CourseOfferingClosureWorkflow::SUPERSEDE_PRIOR_MATERIALIZATION,
                    CourseOfferingClosureWorkflow::EVENT_SUPERSEDED_PRIOR_MATERIALIZATION
                );

                return $this->created($this->createRequest($user, $locked, $trimmed));
            }

            throw CourseOfferingClosureException::requestAlreadyCurrent();
        }));
    }

    public function resubmit(User $user, CourseOfferingClosureRequest $request, ?string $reason = null): CourseOfferingClosureRequest
    {
        $this->assertCanRequest($user);
        $trimmed = $reason === null ? null : $this->requireReason($reason);

        return $this->finish($this->runLocked($request->course_offering_id, function (CourseOffering $locked) use ($user, $request, $trimmed): array {
            $current = $this->lockRequestById((int) $request->course_offering_closure_request_id);
            $this->assertOfferingInDeanScope($user, $locked);

            if (! $current->isCurrent() || $current->status === CourseOfferingClosureWorkflow::STATUS_SUPERSEDED) {
                throw CourseOfferingClosureException::notCurrent();
            }
            if ($current->isMaterialized()) {
                throw CourseOfferingClosureException::alreadyMaterialized();
            }
            if ($current->status !== CourseOfferingClosureWorkflow::STATUS_RETURNED) {
                throw CourseOfferingClosureException::notCurrent();
            }

            if (! $current->identityMatches($locked)) {
                $this->supersedeUnlocked(
                    $user,
                    $current,
                    CourseOfferingClosureWorkflow::SUPERSEDE_IDENTITY_CHANGED,
                    CourseOfferingClosureWorkflow::EVENT_SUPERSEDED_IDENTITY_CHANGED
                );

                return [
                    'request' => $this->loadRequest((int) $current->course_offering_closure_request_id),
                    'error' => CourseOfferingClosureException::REQUEST_STALE,
                ];
            }

            $this->assertOfferingOpenForClosure($locked);

            return $this->created($this->resubmitUnlocked($user, $current, $trimmed));
        }));
    }

    public function approveScientific(User $user, CourseOfferingClosureRequest $request): CourseOfferingClosureRequest
    {
        $this->assertScientificReviewer($user);

        return $this->decide($user, $request, CourseOfferingClosureWorkflow::AUTHORITY_SCIENTIFIC, CourseOfferingClosureWorkflow::REVIEW_APPROVED, null);
    }

    public function returnScientific(User $user, CourseOfferingClosureRequest $request, string $reason): CourseOfferingClosureRequest
    {
        $this->assertScientificReviewer($user);

        return $this->decide($user, $request, CourseOfferingClosureWorkflow::AUTHORITY_SCIENTIFIC, CourseOfferingClosureWorkflow::REVIEW_RETURNED, $reason);
    }

    public function approveAdministrative(User $user, CourseOfferingClosureRequest $request): CourseOfferingClosureRequest
    {
        $this->assertAdministrativeReviewer($user);

        return $this->decide($user, $request, CourseOfferingClosureWorkflow::AUTHORITY_ADMINISTRATIVE, CourseOfferingClosureWorkflow::REVIEW_APPROVED, null);
    }

    public function returnAdministrative(User $user, CourseOfferingClosureRequest $request, string $reason): CourseOfferingClosureRequest
    {
        $this->assertAdministrativeReviewer($user);

        return $this->decide($user, $request, CourseOfferingClosureWorkflow::AUTHORITY_ADMINISTRATIVE, CourseOfferingClosureWorkflow::REVIEW_RETURNED, $reason);
    }

    public function deanRequestsQuery(User $user)
    {
        $this->assertCanView($user);

        return CourseOfferingClosureRequest::query()
            ->where('current_slot', 1)
            ->whereIn('course_offering_id', $this->scopedOfferingIdsQuery($user));
    }

    public function reviewQueueQuery(User $user, string $authority)
    {
        if ($authority === CourseOfferingClosureWorkflow::AUTHORITY_SCIENTIFIC) {
            $this->assertCanReadScientificQueue($user);
        } else {
            $this->assertCanReadAdministrativeQueue($user);
        }

        return CourseOfferingClosureRequest::query()
            ->where('current_slot', 1)
            ->whereIn('course_offering_id', $this->scopedOfferingIdsQuery($user));
    }

    public function assertCanViewRequest(User $user, CourseOfferingClosureRequest $request): void
    {
        $this->assertCanView($user);
        $offering = CourseOffering::query()->findOrFail($request->course_offering_id);
        if (! $this->dataScope->canAccessOffering($user, $offering)
            && ! $this->offeringInAccessibleColleges($user, $offering)) {
            throw CourseOfferingClosureException::offeringOutsideScope();
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
        $relations = [
            'courseOffering.course',
            'courseOffering.academicProgram.department.college',
            'courseOffering.department.college',
            'courseOffering.academicYear',
            'courseOffering.semester',
            'requester',
            'reviews.reviewer',
        ];
        if (SemesterRegistrationPhase6::schemaReady()) $relations[] = 'minimumEnrollmentReview';
        return $relations;
    }

    /**
     * @param  callable(CourseOffering): array{request: CourseOfferingClosureRequest, error: ?string}  $then
     * @return array{request: CourseOfferingClosureRequest, error: ?string}
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
     * @param  array{request: CourseOfferingClosureRequest, error: ?string}  $result
     */
    private function finish(array $result): CourseOfferingClosureRequest
    {
        return match ($result['error']) {
            CourseOfferingClosureException::REQUEST_STALE => throw CourseOfferingClosureException::requestStale(),
            default => $result['request'],
        };
    }

    private function created(CourseOfferingClosureRequest $request): array
    {
        return ['request' => $request, 'error' => null];
    }

    private function decide(
        User $user,
        CourseOfferingClosureRequest $request,
        string $authority,
        string $decision,
        ?string $reason
    ): CourseOfferingClosureRequest {
        return $this->finish($this->runLocked($request->course_offering_id, function (CourseOffering $locked) use ($user, $request, $authority, $decision, $reason): array {
            $current = $this->lockRequestById((int) $request->course_offering_closure_request_id);

            if (! $this->dataScope->canAccessOffering($user, $locked)
                && ! $this->offeringInAccessibleColleges($user, $locked)) {
                throw CourseOfferingClosureException::offeringOutsideScope();
            }

            if ($current->status === CourseOfferingClosureWorkflow::STATUS_SUPERSEDED || ! $current->isCurrent()) {
                throw CourseOfferingClosureException::superseded();
            }

            $scientific = $this->lockCurrentReview($current, CourseOfferingClosureWorkflow::AUTHORITY_SCIENTIFIC);
            $administrative = $this->lockCurrentReview($current, CourseOfferingClosureWorkflow::AUTHORITY_ADMINISTRATIVE);
            $own = $authority === CourseOfferingClosureWorkflow::AUTHORITY_SCIENTIFIC ? $scientific : $administrative;
            if ($own === null || $scientific === null || $administrative === null) {
                throw CourseOfferingClosureException::notCurrent();
            }

            if ($current->isMaterialized()) {
                if ($decision === CourseOfferingClosureWorkflow::REVIEW_APPROVED
                    && $own->status === CourseOfferingClosureWorkflow::REVIEW_APPROVED) {
                    return $this->created($this->loadRequest((int) $current->course_offering_closure_request_id));
                }

                throw CourseOfferingClosureException::alreadyMaterialized();
            }

            if ($own->status === CourseOfferingClosureWorkflow::REVIEW_APPROVED) {
                if ($decision === CourseOfferingClosureWorkflow::REVIEW_APPROVED) {
                    return $this->attemptMaterialize($user, $locked, $current, $scientific, $administrative);
                }

                throw CourseOfferingClosureException::reviewLocked();
            }

            if ($own->status === CourseOfferingClosureWorkflow::REVIEW_RETURNED) {
                $trimmed = trim((string) $reason);
                if ($decision === CourseOfferingClosureWorkflow::REVIEW_RETURNED
                    && $trimmed !== ''
                    && trim((string) $own->reason) === $trimmed) {
                    return $this->created($this->loadRequest((int) $current->course_offering_closure_request_id));
                }

                throw CourseOfferingClosureException::reviewLocked();
            }

            if ($own->status !== CourseOfferingClosureWorkflow::REVIEW_PENDING) {
                throw CourseOfferingClosureException::reviewLocked();
            }

            if ($decision === CourseOfferingClosureWorkflow::REVIEW_RETURNED) {
                $trimmed = trim((string) $reason);
                if ($trimmed === '') {
                    throw CourseOfferingClosureException::returnReasonRequired();
                }
                $own->status = CourseOfferingClosureWorkflow::REVIEW_RETURNED;
                $own->reason = $trimmed;
                $own->reviewed_by_user_id = $user->user_id;
                $own->reviewed_at = now();
                $own->save();
                $this->recordEvent(
                    $current,
                    $authority === CourseOfferingClosureWorkflow::AUTHORITY_SCIENTIFIC
                        ? CourseOfferingClosureWorkflow::EVENT_SCIENTIFIC_RETURNED
                        : CourseOfferingClosureWorkflow::EVENT_ADMINISTRATIVE_RETURNED,
                    $user,
                    $trimmed
                );
            } else {
                $this->assertDistinctApprover($user, $scientific, $administrative, $authority);
                $own->status = CourseOfferingClosureWorkflow::REVIEW_APPROVED;
                $own->reason = null;
                $own->reviewed_by_user_id = $user->user_id;
                $own->reviewed_at = now();
                $own->save();
                $this->recordEvent(
                    $current,
                    $authority === CourseOfferingClosureWorkflow::AUTHORITY_SCIENTIFIC
                        ? CourseOfferingClosureWorkflow::EVENT_SCIENTIFIC_APPROVED
                        : CourseOfferingClosureWorkflow::EVENT_ADMINISTRATIVE_APPROVED,
                    $user,
                    null
                );
            }

            $scientific->refresh();
            $administrative->refresh();

            if ($scientific->status === CourseOfferingClosureWorkflow::REVIEW_RETURNED
                || $administrative->status === CourseOfferingClosureWorkflow::REVIEW_RETURNED) {
                $current->status = CourseOfferingClosureWorkflow::STATUS_RETURNED;
                $current->approved_at = null;
                $current->save();

                return $this->created($this->loadRequest((int) $current->course_offering_closure_request_id));
            }

            if ($scientific->status === CourseOfferingClosureWorkflow::REVIEW_APPROVED
                && $administrative->status === CourseOfferingClosureWorkflow::REVIEW_APPROVED) {
                return $this->attemptMaterialize($user, $locked, $current, $scientific, $administrative);
            }

            $current->status = CourseOfferingClosureWorkflow::STATUS_SUBMITTED;
            $current->approved_at = null;
            $current->save();

            return $this->created($this->loadRequest((int) $current->course_offering_closure_request_id));
        }));
    }

    private function attemptMaterialize(
        User $user,
        CourseOffering $locked,
        CourseOfferingClosureRequest $current,
        CourseOfferingClosureReview $scientific,
        CourseOfferingClosureReview $administrative,
    ): array {
        if ($current->isMaterialized()) {
            return $this->created($this->loadRequest((int) $current->course_offering_closure_request_id));
        }

        if (! $current->isCurrent()) {
            throw CourseOfferingClosureException::superseded();
        }

        if (! $current->identityMatches($locked)) {
            $this->supersedeUnlocked(
                $user,
                $current,
                CourseOfferingClosureWorkflow::SUPERSEDE_IDENTITY_CHANGED,
                CourseOfferingClosureWorkflow::EVENT_SUPERSEDED_IDENTITY_CHANGED
            );

            return [
                'request' => $this->loadRequest((int) $current->course_offering_closure_request_id),
                'error' => CourseOfferingClosureException::REQUEST_STALE,
            ];
        }

        if ((string) $locked->status !== CourseOfferingOpeningService::STATUS_OPEN) {
            throw CourseOfferingClosureException::notRequired();
        }

        if ((string) $scientific->status !== CourseOfferingClosureWorkflow::REVIEW_APPROVED
            || (string) $administrative->status !== CourseOfferingClosureWorkflow::REVIEW_APPROVED
            || $scientific->reviewed_by_user_id === null
            || $administrative->reviewed_by_user_id === null) {
            throw CourseOfferingClosureException::notCurrent();
        }

        if ((int) $scientific->reviewed_by_user_id === (int) $administrative->reviewed_by_user_id) {
            throw CourseOfferingClosureException::sameReviewerForbidden();
        }

        $current->status = CourseOfferingClosureWorkflow::STATUS_APPROVED;
        $current->approved_at = $current->approved_at ?? now();
        $current->save();

        $locked->status = CourseOfferingOpeningService::STATUS_CLOSED;
        $locked->save();

        $this->minimumCancellation->materializeIfLinked($locked, (int) $current->getKey(), $user);

        $current->materialized_at = now();
        $current->current_slot = null;
        $current->save();

        $this->recordEvent($current, CourseOfferingClosureWorkflow::EVENT_MATERIALIZED, $user, null);

        return $this->created($this->loadRequest((int) $current->course_offering_closure_request_id));
    }

    private function createRequest(User $user, CourseOffering $offering, string $reason): CourseOfferingClosureRequest
    {
        try {
            $request = new CourseOfferingClosureRequest([
                'course_offering_id' => $offering->course_offering_id,
                'requested_by_user_id' => $user->user_id,
                'request_reason' => $reason,
                'status' => CourseOfferingClosureWorkflow::STATUS_SUBMITTED,
                'submission_version' => 1,
                'current_slot' => 1,
                'course_id_snapshot' => $offering->course_id,
                // Legacy Offerings may have academic_program_id IS NULL. Store NULL as NULL.
                'academic_program_id_snapshot' => $offering->academic_program_id,
                'academic_year_id_snapshot' => $offering->academic_year_id,
                'semester_id_snapshot' => $offering->semester_id,
                'department_id_snapshot' => $offering->department_id,
                'submitted_at' => now(),
            ]);
            $request->save();
        } catch (QueryException $exception) {
            if ($this->isDuplicateCurrent($exception)) {
                throw CourseOfferingClosureException::requestAlreadyCurrent();
            }
            throw $exception;
        }

        $this->createPendingReviews($request, 1);
        $this->recordEvent($request, CourseOfferingClosureWorkflow::EVENT_SUBMITTED, $user, $reason);

        return $this->loadRequest((int) $request->course_offering_closure_request_id);
    }

    private function resubmitUnlocked(
        User $user,
        CourseOfferingClosureRequest $request,
        ?string $reason
    ): CourseOfferingClosureRequest {
        $nextVersion = (int) $request->submission_version + 1;
        if ($reason !== null) {
            $request->request_reason = $reason;
        }
        $request->submission_version = $nextVersion;
        $request->submitted_at = now();
        $request->status = CourseOfferingClosureWorkflow::STATUS_SUBMITTED;
        $request->approved_at = null;
        $request->save();

        $this->createPendingReviews($request, $nextVersion);
        $this->recordEvent($request, CourseOfferingClosureWorkflow::EVENT_RESUBMITTED, $user, $request->request_reason);

        return $this->loadRequest((int) $request->course_offering_closure_request_id);
    }

    private function supersedeUnlocked(
        User $user,
        CourseOfferingClosureRequest $current,
        string $reasonCode,
        string $eventType
    ): void {
        if ($current->status === CourseOfferingClosureWorkflow::STATUS_SUPERSEDED
            && $current->current_slot === null) {
            return;
        }

        $current->status = CourseOfferingClosureWorkflow::STATUS_SUPERSEDED;
        $current->current_slot = null;
        $current->superseded_at = now();
        $current->supersede_reason = $reasonCode;
        $current->save();

        $this->recordEvent($current, $eventType, $user, $reasonCode);
    }

    private function createPendingReviews(CourseOfferingClosureRequest $request, int $version): void
    {
        foreach ([
            CourseOfferingClosureWorkflow::AUTHORITY_SCIENTIFIC,
            CourseOfferingClosureWorkflow::AUTHORITY_ADMINISTRATIVE,
        ] as $authority) {
            CourseOfferingClosureReview::query()->create([
                'course_offering_closure_request_id' => $request->course_offering_closure_request_id,
                'submission_version' => $version,
                'review_authority' => $authority,
                'status' => CourseOfferingClosureWorkflow::REVIEW_PENDING,
            ]);
        }
    }

    private function recordEvent(
        CourseOfferingClosureRequest $request,
        string $type,
        User $user,
        ?string $notes
    ): void {
        CourseOfferingClosureEvent::query()->create([
            'course_offering_closure_request_id' => $request->course_offering_closure_request_id,
            'event_type' => $type,
            'actor_user_id' => $user->user_id,
            'submission_version' => $request->submission_version,
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }

    private function lockCurrentRequest(int $offeringId): ?CourseOfferingClosureRequest
    {
        return CourseOfferingClosureRequest::query()
            ->where('course_offering_id', $offeringId)
            ->where('current_slot', 1)
            ->lockForUpdate()
            ->first();
    }

    private function lockRequestById(int $id): CourseOfferingClosureRequest
    {
        return CourseOfferingClosureRequest::query()
            ->whereKey($id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockCurrentReview(
        CourseOfferingClosureRequest $request,
        string $authority
    ): ?CourseOfferingClosureReview {
        return CourseOfferingClosureReview::query()
            ->where('course_offering_closure_request_id', $request->course_offering_closure_request_id)
            ->where('submission_version', (int) $request->submission_version)
            ->where('review_authority', $authority)
            ->lockForUpdate()
            ->first();
    }

    private function loadRequest(int $id): CourseOfferingClosureRequest
    {
        return CourseOfferingClosureRequest::query()
            ->with($this->requestDisplayRelations())
            ->findOrFail($id);
    }

    private function assertCanRequest(User $user): void
    {
        if (! $user->isDean() || ! $this->holdsAssignedPermission($user, CourseOfferingClosureWorkflow::PERMISSION_REQUEST)) {
            throw CourseOfferingClosureException::requestForbidden();
        }
    }

    private function assertCanView(User $user): void
    {
        if (! $user->hasPermission(CourseOfferingClosureWorkflow::PERMISSION_VIEW)
            && ! $user->hasPermission(CourseOfferingClosureWorkflow::PERMISSION_REQUEST)
            && ! $user->hasPermission(CourseOfferingClosureWorkflow::PERMISSION_REVIEW_SCIENTIFIC)
            && ! $user->hasPermission(CourseOfferingClosureWorkflow::PERMISSION_REVIEW_ADMINISTRATIVE)) {
            throw CourseOfferingClosureException::forbidden();
        }
    }

    private function assertCanReadScientificQueue(User $user): void
    {
        if (! $user->hasPermission(CourseOfferingClosureWorkflow::PERMISSION_REVIEW_SCIENTIFIC)) {
            throw CourseOfferingClosureException::scientificReviewForbidden();
        }
    }

    private function assertCanReadAdministrativeQueue(User $user): void
    {
        if (! $user->hasPermission(CourseOfferingClosureWorkflow::PERMISSION_REVIEW_ADMINISTRATIVE)) {
            throw CourseOfferingClosureException::administrativeReviewForbidden();
        }
    }

    private function assertScientificReviewer(User $user): void
    {
        if (! $user->isScientificVicePresident()
            || ! $this->holdsAssignedPermission($user, CourseOfferingClosureWorkflow::PERMISSION_REVIEW_SCIENTIFIC)) {
            throw CourseOfferingClosureException::scientificReviewForbidden();
        }
    }

    private function assertAdministrativeReviewer(User $user): void
    {
        if (! $user->isAdministrativeVicePresident()
            || ! $this->holdsAssignedPermission($user, CourseOfferingClosureWorkflow::PERMISSION_REVIEW_ADMINISTRATIVE)) {
            throw CourseOfferingClosureException::administrativeReviewForbidden();
        }
    }

    /**
     * Assigned role_permissions only. Super Admin virtual grants from
     * User::hasPermission() must not impersonate academic authorities.
     */
    private function holdsAssignedPermission(User $user, string $permission): bool
    {
        return $user->effectivePermissions()->contains($permission);
    }

    private function assertDistinctApprover(
        User $user,
        CourseOfferingClosureReview $scientific,
        CourseOfferingClosureReview $administrative,
        string $authority,
    ): void {
        $other = $authority === CourseOfferingClosureWorkflow::AUTHORITY_SCIENTIFIC
            ? $administrative
            : $scientific;
        if ((string) $other->status !== CourseOfferingClosureWorkflow::REVIEW_APPROVED
            || $other->reviewed_by_user_id === null) {
            return;
        }

        if ((int) $other->reviewed_by_user_id === (int) $user->user_id) {
            throw CourseOfferingClosureException::sameReviewerForbidden();
        }
    }

    private function assertOfferingInDeanScope(User $user, CourseOffering $offering): void
    {
        if (! $this->offeringInAccessibleColleges($user, $offering)) {
            throw CourseOfferingClosureException::offeringOutsideScope();
        }
    }

    private function assertOfferingOpenForClosure(CourseOffering $offering): void
    {
        if ((string) $offering->status === CourseOfferingOpeningService::STATUS_CLOSED) {
            throw CourseOfferingClosureException::notRequired();
        }

        if ((string) $offering->status !== CourseOfferingOpeningService::STATUS_OPEN) {
            throw CourseOfferingClosureException::notRequired();
        }
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
            throw CourseOfferingClosureException::reasonRequired();
        }

        return $trimmed;
    }

    private function isDuplicateCurrent(QueryException $exception): bool
    {
        $errorCode = (int) ($exception->errorInfo[1] ?? 0);

        return $errorCode === 1062
            || str_contains($exception->getMessage(), 'uq_cocr_current_slot');
    }
}
