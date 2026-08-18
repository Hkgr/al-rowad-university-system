<?php

namespace App\Services;

use App\Exceptions\TeachingAssignmentException;
use App\Models\CourseOffering;
use App\Models\CourseOfferingInstructor;
use App\Models\FacultyMember;
use App\Models\TeachingAssignmentEvent;
use App\Models\TeachingAssignmentRequest;
use App\Models\TeachingAssignmentReview;
use App\Models\User;
use App\Support\TeachingAssignmentWorkflow;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeachingAssignmentWorkflowService
{
    public function __construct(
        private TeachingAssignmentService $assignments,
        private DataScopeService $dataScope,
    ) {
    }

    public function proposeSlot(User $user, CourseOffering $offering, string $role, int $facultyMemberId): TeachingAssignmentRequest
    {
        $this->assertCanMutate($user);

        return DB::transaction(function () use ($user, $offering, $role, $facultyMemberId): TeachingAssignmentRequest {
            $lockedOffering = $this->lockOffering((int) $offering->course_offering_id);
            $this->assertOfferingInDeanScope($user, $lockedOffering);

            $facultyMember = FacultyMember::query()
                ->with('employee.employeeStatus')
                ->find($facultyMemberId);
            if ($facultyMember === null) {
                throw TeachingAssignmentException::invalidInstructor();
            }

            try {
                $this->assignments->assertValidAssignment($lockedOffering, $facultyMember, $role);
            } catch (ValidationException $exception) {
                throw TeachingAssignmentException::invalidInstructor();
            }

            $current = $this->lockCurrentRequest((int) $lockedOffering->course_offering_id, $role);
            if ($current !== null) {
                $this->lockReviewsInOrder($current);
            }

            if ($current !== null && $current->isRemoval()) {
                $this->supersedeUnlocked($user, $current, TeachingAssignmentWorkflow::EVENT_SUPERSEDED, null);
                $current = null;
            }

            if ($current === null) {
                return $this->createAssignRequest($user, $lockedOffering, $facultyMember, $role);
            }

            if ((int) $current->faculty_member_id === (int) $facultyMember->faculty_member_id) {
                if ($current->status === TeachingAssignmentWorkflow::STATUS_APPROVED) {
                    return $this->loadRequest((int) $current->teaching_assignment_request_id);
                }
                if ($current->status === TeachingAssignmentWorkflow::STATUS_RETURNED) {
                    return $this->resubmitUnlocked($user, $lockedOffering, $current, null);
                }

                return $this->loadRequest((int) $current->teaching_assignment_request_id);
            }

            return $this->replaceUnlocked($user, $current, $lockedOffering, $facultyMember, $role);
        });
    }

    public function requestRemoval(
        User $user,
        CourseOffering $offering,
        string $role,
        string $reason
    ): TeachingAssignmentRequest {
        $this->assertCanMutate($user);
        $this->assertPhase8Schema();
        $trimmed = $this->requireRemovalReason($reason);

        return DB::transaction(function () use ($user, $offering, $role, $trimmed): TeachingAssignmentRequest {
            $lockedOffering = $this->lockOffering((int) $offering->course_offering_id);
            $this->assertOfferingInDeanScope($user, $lockedOffering);
            $this->assertOfferingClosedForRemoval($lockedOffering);

            $current = $this->lockCurrentRequest((int) $lockedOffering->course_offering_id, $role);
            if ($current !== null) {
                $this->lockReviewsInOrder($current);
            }

            $slot = $this->lockActiveSlot((int) $lockedOffering->course_offering_id, $role);
            if ($slot === null) {
                throw TeachingAssignmentException::removalNotRequired();
            }

            if ($current !== null && $current->isRemoval()) {
                $sameTarget = (int) $current->target_course_offering_instructor_id === (int) $slot->course_offering_instructor_id
                    && (int) $current->faculty_member_id === (int) $slot->faculty_member_id;

                if ($sameTarget) {
                    if ($current->status === TeachingAssignmentWorkflow::STATUS_APPROVED) {
                        throw TeachingAssignmentException::removalNotRequired();
                    }
                    if ($current->status === TeachingAssignmentWorkflow::STATUS_RETURNED) {
                        return $this->resubmitUnlocked($user, $lockedOffering, $current, $trimmed);
                    }

                    return $this->loadRequest((int) $current->teaching_assignment_request_id);
                }

                $this->supersedeUnlocked(
                    $user,
                    $current,
                    TeachingAssignmentWorkflow::EVENT_REMOVAL_STALE,
                    'target_changed'
                );
                $current = null;
            }

            if ($current !== null) {
                $this->supersedeUnlocked($user, $current, TeachingAssignmentWorkflow::EVENT_SUPERSEDED, null);
            }

            return $this->createRemovalRequest($user, $lockedOffering, $slot, $role, $trimmed);
        });
    }

    public function withdrawRemoval(User $user, TeachingAssignmentRequest $request): TeachingAssignmentRequest
    {
        $this->assertCanMutate($user);
        $this->assertPhase8Schema();

        return DB::transaction(function () use ($user, $request): TeachingAssignmentRequest {
            [$lockedOffering, $current] = $this->lockOfferingThenRequest(
                (int) $request->teaching_assignment_request_id
            );
            $this->assertOfferingInDeanScope($user, $lockedOffering);
            $this->lockReviewsInOrder($current);

            if (! $current->isRemoval() || ! $current->isCurrent()) {
                throw TeachingAssignmentException::removalWithdrawForbidden();
            }
            if ($current->status === TeachingAssignmentWorkflow::STATUS_APPROVED) {
                throw TeachingAssignmentException::removalWithdrawForbidden();
            }
            if (! in_array($current->status, [
                TeachingAssignmentWorkflow::STATUS_SUBMITTED,
                TeachingAssignmentWorkflow::STATUS_RETURNED,
            ], true)) {
                throw TeachingAssignmentException::removalWithdrawForbidden();
            }

            $this->supersedeUnlocked(
                $user,
                $current,
                TeachingAssignmentWorkflow::EVENT_REMOVAL_WITHDRAWN,
                null
            );

            return $this->loadRequest((int) $current->teaching_assignment_request_id);
        });
    }

    public function resubmit(
        User $user,
        TeachingAssignmentRequest $request,
        ?string $reason = null
    ): TeachingAssignmentRequest {
        $this->assertCanMutate($user);

        return DB::transaction(function () use ($user, $request, $reason): TeachingAssignmentRequest {
            [$lockedOffering, $current] = $this->lockOfferingThenRequest(
                (int) $request->teaching_assignment_request_id
            );
            $this->assertOfferingInDeanScope($user, $lockedOffering);
            $this->lockReviewsInOrder($current);

            if (! $current->isCurrent()) {
                throw TeachingAssignmentException::notCurrent();
            }
            if ($current->status === TeachingAssignmentWorkflow::STATUS_SUPERSEDED) {
                throw TeachingAssignmentException::superseded();
            }
            if ($current->status !== TeachingAssignmentWorkflow::STATUS_RETURNED) {
                throw TeachingAssignmentException::notCurrent();
            }

            $trimmed = $reason === null ? null : trim($reason);
            if ($current->isRemoval()) {
                $this->assertOfferingClosedForRemoval($lockedOffering);
                if ($trimmed === '') {
                    throw TeachingAssignmentException::removalReasonRequired();
                }
            }

            return $this->resubmitUnlocked($user, $lockedOffering, $current, $trimmed);
        });
    }

    public function replace(User $user, TeachingAssignmentRequest $request, int $facultyMemberId): TeachingAssignmentRequest
    {
        $this->assertCanMutate($user);

        return DB::transaction(function () use ($user, $request, $facultyMemberId): TeachingAssignmentRequest {
            [$lockedOffering, $current] = $this->lockOfferingThenRequest(
                (int) $request->teaching_assignment_request_id
            );
            $this->assertOfferingInDeanScope($user, $lockedOffering);
            $this->lockReviewsInOrder($current);

            if (! $current->isCurrent()) {
                throw TeachingAssignmentException::notCurrent();
            }

            $facultyMember = FacultyMember::query()
                ->with('employee.employeeStatus')
                ->find($facultyMemberId);
            if ($facultyMember === null) {
                throw TeachingAssignmentException::invalidInstructor();
            }

            try {
                $this->assignments->assertValidAssignment(
                    $lockedOffering,
                    $facultyMember,
                    (string) $current->instructor_role
                );
            } catch (ValidationException $exception) {
                throw TeachingAssignmentException::invalidInstructor();
            }

            if ($current->isRemoval()) {
                $this->supersedeUnlocked($user, $current, TeachingAssignmentWorkflow::EVENT_SUPERSEDED, null);

                return $this->createAssignRequest(
                    $user,
                    $lockedOffering,
                    $facultyMember,
                    (string) $current->instructor_role
                );
            }

            if ((int) $current->faculty_member_id === (int) $facultyMember->faculty_member_id) {
                throw TeachingAssignmentException::materialChangeRequiresNewCycle();
            }

            return $this->replaceUnlocked(
                $user,
                $current,
                $lockedOffering,
                $facultyMember,
                (string) $current->instructor_role
            );
        });
    }

    public function approveScientific(User $user, TeachingAssignmentRequest $request): TeachingAssignmentRequest
    {
        $this->assertScientificReviewer($user);

        return $this->decide($user, $request, TeachingAssignmentWorkflow::AUTHORITY_SCIENTIFIC, TeachingAssignmentWorkflow::REVIEW_APPROVED, null);
    }

    public function returnScientific(User $user, TeachingAssignmentRequest $request, string $reason): TeachingAssignmentRequest
    {
        $this->assertScientificReviewer($user);

        return $this->decide($user, $request, TeachingAssignmentWorkflow::AUTHORITY_SCIENTIFIC, TeachingAssignmentWorkflow::REVIEW_RETURNED, $reason);
    }

    public function approveAdministrative(User $user, TeachingAssignmentRequest $request): TeachingAssignmentRequest
    {
        $this->assertAdministrativeReviewer($user);

        return $this->decide($user, $request, TeachingAssignmentWorkflow::AUTHORITY_ADMINISTRATIVE, TeachingAssignmentWorkflow::REVIEW_APPROVED, null);
    }

    public function returnAdministrative(User $user, TeachingAssignmentRequest $request, string $reason): TeachingAssignmentRequest
    {
        $this->assertAdministrativeReviewer($user);

        return $this->decide($user, $request, TeachingAssignmentWorkflow::AUTHORITY_ADMINISTRATIVE, TeachingAssignmentWorkflow::REVIEW_RETURNED, $reason);
    }

    public function deanRequestsQuery(User $user)
    {
        $this->assertCanView($user);

        return TeachingAssignmentRequest::query()
            ->where('current_slot', 1)
            ->whereIn(
                'course_offering_id',
                $this->scopedOfferingIdsQuery($user)
            );
    }

    public function reviewQueueQuery(User $user, string $authority)
    {
        if ($authority === TeachingAssignmentWorkflow::AUTHORITY_SCIENTIFIC) {
            $this->assertCanReadScientificQueue($user);
        } else {
            $this->assertCanReadAdministrativeQueue($user);
        }

        return TeachingAssignmentRequest::query()
            ->where('current_slot', 1)
            ->whereIn('course_offering_id', $this->scopedOfferingIdsQuery($user));
    }

    public function assertCanViewRequest(User $user, TeachingAssignmentRequest $request): void
    {
        $this->assertCanView($user);
        $offering = CourseOffering::query()->findOrFail($request->course_offering_id);
        if (! $this->dataScope->canAccessOffering($user, $offering)
            && ! $this->offeringInAccessibleColleges($user, $offering)) {
            throw TeachingAssignmentException::offeringOutsideScope();
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
            'courseOffering.offeringInstructors.facultyMember.employee.organizationalUnit',
            'facultyMember.employee.organizationalUnit',
            'requester',
            'reviews.reviewer',
        ];
        if (TeachingAssignmentWorkflow::schemaReady()) {
            $relations[] = 'targetInstructor.facultyMember.employee.organizationalUnit';
        }

        return $relations;
    }

    private function decide(
        User $user,
        TeachingAssignmentRequest $request,
        string $authority,
        string $decision,
        ?string $reason
    ): TeachingAssignmentRequest {
        return DB::transaction(function () use ($user, $request, $authority, $decision, $reason): TeachingAssignmentRequest {
            [$offering, $current] = $this->lockOfferingThenRequest(
                (int) $request->teaching_assignment_request_id
            );

            if (! $this->dataScope->canAccessOffering($user, $offering)
                && ! $this->offeringInAccessibleColleges($user, $offering)) {
                throw TeachingAssignmentException::offeringOutsideScope();
            }

            if ($current->status === TeachingAssignmentWorkflow::STATUS_SUPERSEDED || ! $current->isCurrent()) {
                throw TeachingAssignmentException::superseded();
            }

            $reviews = $this->lockReviewsInOrder($current);
            $own = $reviews->get($authority);
            if ($own === null) {
                throw TeachingAssignmentException::notCurrent();
            }

            if ($current->isRemoval()) {
                $this->lockTargetSlot($current);
            } else {
                $this->lockRoleSlots((int) $offering->course_offering_id, (string) $current->instructor_role);
            }

            if ($current->status === TeachingAssignmentWorkflow::STATUS_APPROVED) {
                if ($decision === TeachingAssignmentWorkflow::REVIEW_APPROVED
                    && $own->status === TeachingAssignmentWorkflow::REVIEW_APPROVED) {
                    return $this->loadRequest((int) $current->teaching_assignment_request_id);
                }

                throw TeachingAssignmentException::alreadyEffective();
            }

            if ($own->status === TeachingAssignmentWorkflow::REVIEW_APPROVED) {
                if ($decision === TeachingAssignmentWorkflow::REVIEW_APPROVED) {
                    return $this->loadRequest((int) $current->teaching_assignment_request_id);
                }

                throw TeachingAssignmentException::reviewLocked();
            }

            if ($own->status === TeachingAssignmentWorkflow::REVIEW_RETURNED) {
                $trimmed = trim((string) $reason);
                if ($decision === TeachingAssignmentWorkflow::REVIEW_RETURNED
                    && $trimmed !== ''
                    && trim((string) $own->reason) === $trimmed) {
                    return $this->loadRequest((int) $current->teaching_assignment_request_id);
                }

                throw TeachingAssignmentException::reviewLocked();
            }

            if ($own->status !== TeachingAssignmentWorkflow::REVIEW_PENDING) {
                throw TeachingAssignmentException::reviewLocked();
            }

            if ($decision === TeachingAssignmentWorkflow::REVIEW_APPROVED) {
                $this->assertDistinctApprover($user, $reviews, $authority);
            }

            if ($decision === TeachingAssignmentWorkflow::REVIEW_RETURNED) {
                $trimmed = trim((string) $reason);
                if ($trimmed === '') {
                    throw TeachingAssignmentException::returnReasonRequired();
                }
                $own->status = TeachingAssignmentWorkflow::REVIEW_RETURNED;
                $own->reason = $trimmed;
                $own->reviewed_by_user_id = $user->user_id;
                $own->reviewed_at = now();
                $own->save();
                $this->recordEvent(
                    $current,
                    $authority === TeachingAssignmentWorkflow::AUTHORITY_SCIENTIFIC
                        ? TeachingAssignmentWorkflow::EVENT_SCIENTIFIC_RETURNED
                        : TeachingAssignmentWorkflow::EVENT_ADMINISTRATIVE_RETURNED,
                    $user,
                    $trimmed
                );
            } else {
                $own->status = TeachingAssignmentWorkflow::REVIEW_APPROVED;
                $own->reason = null;
                $own->reviewed_by_user_id = $user->user_id;
                $own->reviewed_at = now();
                $own->save();
                $this->recordEvent(
                    $current,
                    $authority === TeachingAssignmentWorkflow::AUTHORITY_SCIENTIFIC
                        ? TeachingAssignmentWorkflow::EVENT_SCIENTIFIC_APPROVED
                        : TeachingAssignmentWorkflow::EVENT_ADMINISTRATIVE_APPROVED,
                    $user,
                    null
                );
            }

            $this->refreshAggregateAndMaterialize($user, $offering, $current, $reviews);

            return $this->loadRequest((int) $current->teaching_assignment_request_id);
        });
    }

    /**
     * @param  Collection<string, TeachingAssignmentReview>  $reviews
     */
    private function refreshAggregateAndMaterialize(
        User $user,
        CourseOffering $offering,
        TeachingAssignmentRequest $request,
        Collection $reviews
    ): void {
        $scientific = $reviews->get(TeachingAssignmentWorkflow::AUTHORITY_SCIENTIFIC);
        $administrative = $reviews->get(TeachingAssignmentWorkflow::AUTHORITY_ADMINISTRATIVE);
        if ($scientific === null || $administrative === null) {
            return;
        }

        $scientific->refresh();
        $administrative->refresh();

        if ($scientific->status === TeachingAssignmentWorkflow::REVIEW_RETURNED
            || $administrative->status === TeachingAssignmentWorkflow::REVIEW_RETURNED) {
            $request->status = TeachingAssignmentWorkflow::STATUS_RETURNED;
            $request->approved_at = null;
            $request->save();

            return;
        }

        if ($scientific->status === TeachingAssignmentWorkflow::REVIEW_APPROVED
            && $administrative->status === TeachingAssignmentWorkflow::REVIEW_APPROVED) {
            $wasApproved = $request->status === TeachingAssignmentWorkflow::STATUS_APPROVED;
            $request->status = TeachingAssignmentWorkflow::STATUS_APPROVED;
            $request->approved_at = $request->approved_at ?? now();
            $request->save();
            if (! $wasApproved) {
                $this->materializeEffective($user, $offering, $request);
            }

            return;
        }

        $request->status = TeachingAssignmentWorkflow::STATUS_SUBMITTED;
        $request->approved_at = null;
        $request->save();
    }

    private function materializeEffective(
        User $user,
        CourseOffering $offering,
        TeachingAssignmentRequest $request
    ): void {
        $offering->loadMissing('course');

        if ($request->isRemoval()) {
            $this->materializeRemoval($user, $offering, $request);

            return;
        }

        $previous = CourseOfferingInstructor::query()
            ->where('course_offering_id', $offering->course_offering_id)
            ->where('instructor_role', $request->instructor_role)
            ->lockForUpdate()
            ->first();

        $replacingActive = $previous !== null
            && $previous->is_active
            && (int) $previous->faculty_member_id !== (int) $request->faculty_member_id;

        $this->assignments->materializeApprovedSlot(
            $offering,
            (string) $request->instructor_role,
            (int) $request->faculty_member_id
        );

        $this->recordEvent(
            $request,
            $replacingActive
                ? TeachingAssignmentWorkflow::EVENT_EFFECTIVE_CHANGED
                : TeachingAssignmentWorkflow::EVENT_EFFECTIVE_CREATED,
            $user,
            null
        );
    }

    private function materializeRemoval(
        User $user,
        CourseOffering $offering,
        TeachingAssignmentRequest $request
    ): void {
        if ((string) $offering->status !== CourseOfferingOpeningService::STATUS_CLOSED) {
            $this->supersedeUnlocked(
                $user,
                $request,
                TeachingAssignmentWorkflow::EVENT_REMOVAL_STALE,
                'offering_opened'
            );

            throw TeachingAssignmentException::removalRequiresClosedOffering();
        }

        try {
            $this->assignments->materializeApprovedRemoval($offering, $request);
        } catch (TeachingAssignmentException $exception) {
            if ($exception->errorCode === TeachingAssignmentException::REMOVAL_STALE) {
                $this->supersedeUnlocked(
                    $user,
                    $request,
                    TeachingAssignmentWorkflow::EVENT_REMOVAL_STALE,
                    'target_mismatch'
                );
            }

            throw $exception;
        }

        $this->recordEvent(
            $request,
            TeachingAssignmentWorkflow::EVENT_EFFECTIVE_REMOVED,
            $user,
            null
        );
    }

    private function createAssignRequest(
        User $user,
        CourseOffering $offering,
        FacultyMember $facultyMember,
        string $role
    ): TeachingAssignmentRequest {
        try {
            $attributes = [
                'course_offering_id' => $offering->course_offering_id,
                'faculty_member_id' => $facultyMember->faculty_member_id,
                'instructor_role' => $role,
                'status' => TeachingAssignmentWorkflow::STATUS_SUBMITTED,
                'submission_version' => 1,
                'current_slot' => 1,
                'requested_by_user_id' => $user->user_id,
                'submitted_at' => now(),
            ];
            if (TeachingAssignmentWorkflow::schemaReady()) {
                $attributes['action_type'] = TeachingAssignmentWorkflow::ACTION_ASSIGN;
                $attributes['action_reason'] = null;
                $attributes['target_course_offering_instructor_id'] = null;
            }
            $request = new TeachingAssignmentRequest($attributes);
            $request->save();
        } catch (QueryException $exception) {
            if ($this->isDuplicateCurrent($exception)) {
                throw TeachingAssignmentException::duplicateCurrent();
            }
            throw $exception;
        }

        $this->createPendingReviews($request);
        $this->recordEvent($request, TeachingAssignmentWorkflow::EVENT_SUBMITTED, $user, null);

        return $this->loadRequest((int) $request->teaching_assignment_request_id);
    }

    private function createRemovalRequest(
        User $user,
        CourseOffering $offering,
        CourseOfferingInstructor $slot,
        string $role,
        string $reason
    ): TeachingAssignmentRequest {
        try {
            $request = new TeachingAssignmentRequest([
                'course_offering_id' => $offering->course_offering_id,
                'faculty_member_id' => $slot->faculty_member_id,
                'instructor_role' => $role,
                'action_type' => TeachingAssignmentWorkflow::ACTION_REMOVE,
                'action_reason' => $reason,
                'target_course_offering_instructor_id' => $slot->course_offering_instructor_id,
                'status' => TeachingAssignmentWorkflow::STATUS_SUBMITTED,
                'submission_version' => 1,
                'current_slot' => 1,
                'requested_by_user_id' => $user->user_id,
                'submitted_at' => now(),
            ]);
            $request->save();
        } catch (QueryException $exception) {
            if ($this->isDuplicateCurrent($exception)) {
                throw TeachingAssignmentException::duplicateCurrent();
            }
            throw $exception;
        }

        $this->createPendingReviews($request);
        $this->recordEvent($request, TeachingAssignmentWorkflow::EVENT_SUBMITTED, $user, $reason);

        return $this->loadRequest((int) $request->teaching_assignment_request_id);
    }

    private function resubmitUnlocked(
        User $user,
        CourseOffering $offering,
        TeachingAssignmentRequest $request,
        ?string $reason
    ): TeachingAssignmentRequest {
        $reviews = $this->lockReviewsInOrder($request);

        foreach ($reviews as $review) {
            if ($review->status === TeachingAssignmentWorkflow::REVIEW_RETURNED) {
                $review->status = TeachingAssignmentWorkflow::REVIEW_PENDING;
                $review->reason = null;
                $review->reviewed_by_user_id = null;
                $review->reviewed_at = null;
                $review->save();
            }
        }

        if ($request->isRemoval() && $reason !== null) {
            $request->action_reason = $reason;
        }

        $request->submission_version = (int) $request->submission_version + 1;
        $request->submitted_at = now();
        $request->status = TeachingAssignmentWorkflow::STATUS_SUBMITTED;
        $request->save();
        $this->recordEvent($request, TeachingAssignmentWorkflow::EVENT_RESUBMITTED, $user, $reason);

        $this->refreshAggregateAndMaterialize($user, $offering, $request, $reviews);

        return $this->loadRequest((int) $request->teaching_assignment_request_id);
    }

    private function replaceUnlocked(
        User $user,
        TeachingAssignmentRequest $current,
        CourseOffering $offering,
        FacultyMember $facultyMember,
        string $role
    ): TeachingAssignmentRequest {
        $this->supersedeUnlocked($user, $current, TeachingAssignmentWorkflow::EVENT_SUPERSEDED, null);

        $replacement = $this->createAssignRequest($user, $offering, $facultyMember, $role);
        $current->superseded_by_request_id = $replacement->teaching_assignment_request_id;
        $current->save();

        return $replacement;
    }

    private function supersedeUnlocked(
        User $user,
        TeachingAssignmentRequest $current,
        string $eventType,
        ?string $notes
    ): void {
        if ($current->status === TeachingAssignmentWorkflow::STATUS_SUPERSEDED
            && $current->current_slot === null) {
            return;
        }

        $current->status = TeachingAssignmentWorkflow::STATUS_SUPERSEDED;
        $current->current_slot = null;
        $current->superseded_at = now();
        $current->save();
        $this->recordEvent($current, $eventType, $user, $notes);
    }

    private function createPendingReviews(TeachingAssignmentRequest $request): void
    {
        foreach ([
            TeachingAssignmentWorkflow::AUTHORITY_SCIENTIFIC,
            TeachingAssignmentWorkflow::AUTHORITY_ADMINISTRATIVE,
        ] as $authority) {
            TeachingAssignmentReview::query()->create([
                'teaching_assignment_request_id' => $request->teaching_assignment_request_id,
                'review_authority' => $authority,
                'status' => TeachingAssignmentWorkflow::REVIEW_PENDING,
            ]);
        }
    }

    private function recordEvent(
        TeachingAssignmentRequest $request,
        string $type,
        User $user,
        ?string $notes
    ): void {
        TeachingAssignmentEvent::query()->create([
            'teaching_assignment_request_id' => $request->teaching_assignment_request_id,
            'event_type' => $type,
            'actor_user_id' => $user->user_id,
            'submission_version' => $request->submission_version,
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array{0: CourseOffering, 1: TeachingAssignmentRequest}
     */
    private function lockOfferingThenRequest(int $requestId): array
    {
        $peek = TeachingAssignmentRequest::query()->findOrFail($requestId);
        $offering = $this->lockOffering((int) $peek->course_offering_id);
        $current = TeachingAssignmentRequest::query()
            ->whereKey($requestId)
            ->lockForUpdate()
            ->firstOrFail();

        if ((int) $current->course_offering_id !== (int) $offering->course_offering_id) {
            throw TeachingAssignmentException::notCurrent();
        }

        return [$offering, $current];
    }

    private function lockOffering(int $offeringId): CourseOffering
    {
        return CourseOffering::query()
            ->whereKey($offeringId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockCurrentRequest(int $offeringId, string $role): ?TeachingAssignmentRequest
    {
        return TeachingAssignmentRequest::query()
            ->where('course_offering_id', $offeringId)
            ->where('instructor_role', $role)
            ->where('current_slot', 1)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @return Collection<string, TeachingAssignmentReview>
     */
    private function lockReviewsInOrder(TeachingAssignmentRequest $request): Collection
    {
        $scientific = TeachingAssignmentReview::query()
            ->where('teaching_assignment_request_id', $request->teaching_assignment_request_id)
            ->where('review_authority', TeachingAssignmentWorkflow::AUTHORITY_SCIENTIFIC)
            ->lockForUpdate()
            ->first();
        $administrative = TeachingAssignmentReview::query()
            ->where('teaching_assignment_request_id', $request->teaching_assignment_request_id)
            ->where('review_authority', TeachingAssignmentWorkflow::AUTHORITY_ADMINISTRATIVE)
            ->lockForUpdate()
            ->first();

        $reviews = collect();
        if ($scientific !== null) {
            $reviews->put(TeachingAssignmentWorkflow::AUTHORITY_SCIENTIFIC, $scientific);
        }
        if ($administrative !== null) {
            $reviews->put(TeachingAssignmentWorkflow::AUTHORITY_ADMINISTRATIVE, $administrative);
        }

        return $reviews;
    }

    private function lockActiveSlot(int $offeringId, string $role): ?CourseOfferingInstructor
    {
        return CourseOfferingInstructor::query()
            ->where('course_offering_id', $offeringId)
            ->where('instructor_role', $role)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();
    }

    private function lockRoleSlots(int $offeringId, string $role): void
    {
        CourseOfferingInstructor::query()
            ->where('course_offering_id', $offeringId)
            ->where('instructor_role', $role)
            ->lockForUpdate()
            ->get();
    }

    private function lockTargetSlot(TeachingAssignmentRequest $request): void
    {
        if ($request->target_course_offering_instructor_id === null) {
            return;
        }

        CourseOfferingInstructor::query()
            ->whereKey($request->target_course_offering_instructor_id)
            ->lockForUpdate()
            ->first();
    }

    private function loadRequest(int $id): TeachingAssignmentRequest
    {
        return TeachingAssignmentRequest::query()
            ->with($this->requestDisplayRelations())
            ->findOrFail($id);
    }

    private function assertCanMutate(User $user): void
    {
        if (! $user->isDean() || ! $this->holdsAssignedPermission($user, TeachingAssignmentWorkflow::PERMISSION_MANAGE)) {
            throw TeachingAssignmentException::manageForbidden();
        }
    }

    private function assertCanView(User $user): void
    {
        if (! $user->hasPermission(TeachingAssignmentWorkflow::PERMISSION_VIEW)
            && ! $user->hasPermission(TeachingAssignmentWorkflow::PERMISSION_MANAGE)
            && ! $user->hasPermission(TeachingAssignmentWorkflow::PERMISSION_REVIEW_SCIENTIFIC)
            && ! $user->hasPermission(TeachingAssignmentWorkflow::PERMISSION_REVIEW_ADMINISTRATIVE)) {
            throw TeachingAssignmentException::offeringOutsideScope();
        }
    }

    private function assertCanReadScientificQueue(User $user): void
    {
        if (! $user->hasPermission(TeachingAssignmentWorkflow::PERMISSION_REVIEW_SCIENTIFIC)) {
            throw TeachingAssignmentException::scientificReviewForbidden();
        }
    }

    private function assertCanReadAdministrativeQueue(User $user): void
    {
        if (! $user->hasPermission(TeachingAssignmentWorkflow::PERMISSION_REVIEW_ADMINISTRATIVE)) {
            throw TeachingAssignmentException::administrativeReviewForbidden();
        }
    }

    private function assertScientificReviewer(User $user): void
    {
        if (! $user->isScientificVicePresident()
            || ! $this->holdsAssignedPermission($user, TeachingAssignmentWorkflow::PERMISSION_REVIEW_SCIENTIFIC)) {
            throw TeachingAssignmentException::scientificReviewForbidden();
        }
    }

    private function assertAdministrativeReviewer(User $user): void
    {
        if (! $user->isAdministrativeVicePresident()
            || ! $this->holdsAssignedPermission($user, TeachingAssignmentWorkflow::PERMISSION_REVIEW_ADMINISTRATIVE)) {
            throw TeachingAssignmentException::administrativeReviewForbidden();
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

    /**
     * The two approvals that materialize a teaching assignment cannot come
     * from the same user_id on the current request cycle.
     *
     * @param  Collection<string, TeachingAssignmentReview>  $reviews
     */
    private function assertDistinctApprover(User $user, Collection $reviews, string $authority): void
    {
        $otherAuthority = $authority === TeachingAssignmentWorkflow::AUTHORITY_SCIENTIFIC
            ? TeachingAssignmentWorkflow::AUTHORITY_ADMINISTRATIVE
            : TeachingAssignmentWorkflow::AUTHORITY_SCIENTIFIC;
        $other = $reviews->get($otherAuthority);
        if ($other === null
            || (string) $other->status !== TeachingAssignmentWorkflow::REVIEW_APPROVED
            || $other->reviewed_by_user_id === null) {
            return;
        }

        if ((int) $other->reviewed_by_user_id === (int) $user->user_id) {
            throw TeachingAssignmentException::sameReviewerForbidden();
        }
    }

    private function assertOfferingInDeanScope(User $user, CourseOffering $offering): void
    {
        if (! $this->offeringInAccessibleColleges($user, $offering)) {
            throw TeachingAssignmentException::offeringOutsideScope();
        }
    }

    private function assertOfferingClosedForRemoval(CourseOffering $offering): void
    {
        if ((string) $offering->status !== CourseOfferingOpeningService::STATUS_CLOSED) {
            throw TeachingAssignmentException::removalRequiresClosedOffering();
        }
    }

    private function assertPhase8Schema(): void
    {
        if (! TeachingAssignmentWorkflow::schemaReady()) {
            throw TeachingAssignmentException::actionInvalid();
        }
    }

    private function requireRemovalReason(string $reason): string
    {
        $trimmed = trim($reason);
        if ($trimmed === '') {
            throw TeachingAssignmentException::removalReasonRequired();
        }

        return $trimmed;
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

    private function isDuplicateCurrent(QueryException $exception): bool
    {
        $errorCode = (int) ($exception->errorInfo[1] ?? 0);

        return $errorCode === 1062
            || str_contains($exception->getMessage(), 'uq_tar_current_slot');
    }
}
