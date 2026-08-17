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
        $this->assertCanManage($user, $offering);

        return DB::transaction(function () use ($user, $offering, $role, $facultyMemberId): TeachingAssignmentRequest {
            $lockedOffering = CourseOffering::query()
                ->whereKey($offering->course_offering_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assignments->assertCanManageAssignments($user, $lockedOffering);
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

            if ($current === null) {
                return $this->createRequest($user, $lockedOffering, $facultyMember, $role);
            }

            if ((int) $current->faculty_member_id === (int) $facultyMember->faculty_member_id) {
                if ($current->status === TeachingAssignmentWorkflow::STATUS_APPROVED) {
                    return $this->loadRequest((int) $current->teaching_assignment_request_id);
                }
                if ($current->status === TeachingAssignmentWorkflow::STATUS_RETURNED) {
                    return $this->resubmitUnlocked($user, $current);
                }

                return $this->loadRequest((int) $current->teaching_assignment_request_id);
            }

            return $this->replaceUnlocked($user, $current, $lockedOffering, $facultyMember, $role);
        });
    }

    public function resubmit(User $user, TeachingAssignmentRequest $request): TeachingAssignmentRequest
    {
        return DB::transaction(function () use ($user, $request): TeachingAssignmentRequest {
            $current = TeachingAssignmentRequest::query()
                ->whereKey($request->teaching_assignment_request_id)
                ->lockForUpdate()
                ->firstOrFail();

            $offering = CourseOffering::query()
                ->whereKey($current->course_offering_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assignments->assertCanManageAssignments($user, $offering);
            $this->assertOfferingInDeanScope($user, $offering);

            if (! $current->isCurrent()) {
                throw TeachingAssignmentException::notCurrent();
            }
            if ($current->status === TeachingAssignmentWorkflow::STATUS_SUPERSEDED) {
                throw TeachingAssignmentException::superseded();
            }
            if ($current->status !== TeachingAssignmentWorkflow::STATUS_RETURNED) {
                throw TeachingAssignmentException::notCurrent();
            }

            return $this->resubmitUnlocked($user, $current);
        });
    }

    public function replace(User $user, TeachingAssignmentRequest $request, int $facultyMemberId): TeachingAssignmentRequest
    {
        return DB::transaction(function () use ($user, $request, $facultyMemberId): TeachingAssignmentRequest {
            $current = TeachingAssignmentRequest::query()
                ->whereKey($request->teaching_assignment_request_id)
                ->lockForUpdate()
                ->firstOrFail();

            $offering = CourseOffering::query()
                ->whereKey($current->course_offering_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assignments->assertCanManageAssignments($user, $offering);
            $this->assertOfferingInDeanScope($user, $offering);

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
                $this->assignments->assertValidAssignment($offering, $facultyMember, (string) $current->instructor_role);
            } catch (ValidationException $exception) {
                throw TeachingAssignmentException::invalidInstructor();
            }

            if ((int) $current->faculty_member_id === (int) $facultyMember->faculty_member_id) {
                throw TeachingAssignmentException::materialChangeRequiresNewCycle();
            }

            return $this->replaceUnlocked($user, $current, $offering, $facultyMember, (string) $current->instructor_role);
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

        $query = TeachingAssignmentRequest::query()
            ->where('current_slot', 1)
            ->whereIn(
                'course_offering_id',
                $this->scopedOfferingIdsQuery($user)
            );

        return $query;
    }

    public function reviewQueueQuery(User $user, string $authority)
    {
        if ($authority === TeachingAssignmentWorkflow::AUTHORITY_SCIENTIFIC) {
            $this->assertScientificReviewer($user);
        } else {
            $this->assertAdministrativeReviewer($user);
        }

        $query = TeachingAssignmentRequest::query()
            ->where('current_slot', 1)
            ->whereIn('course_offering_id', $this->scopedOfferingIdsQuery($user));

        return $query;
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
        return [
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
    }

    private function decide(
        User $user,
        TeachingAssignmentRequest $request,
        string $authority,
        string $decision,
        ?string $reason
    ): TeachingAssignmentRequest {
        return DB::transaction(function () use ($user, $request, $authority, $decision, $reason): TeachingAssignmentRequest {
            $current = TeachingAssignmentRequest::query()
                ->whereKey($request->teaching_assignment_request_id)
                ->lockForUpdate()
                ->firstOrFail();

            $offering = CourseOffering::query()
                ->whereKey($current->course_offering_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->dataScope->canAccessOffering($user, $offering)
                && ! $this->offeringInAccessibleColleges($user, $offering)) {
                throw TeachingAssignmentException::offeringOutsideScope();
            }

            if ($current->status === TeachingAssignmentWorkflow::STATUS_SUPERSEDED || ! $current->isCurrent()) {
                throw TeachingAssignmentException::superseded();
            }

            $reviews = TeachingAssignmentReview::query()
                ->where('teaching_assignment_request_id', $current->teaching_assignment_request_id)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (TeachingAssignmentReview $review): string => (string) $review->review_authority);

            $own = $reviews->get($authority);
            if ($own === null) {
                throw TeachingAssignmentException::notCurrent();
            }

            if ($decision === TeachingAssignmentWorkflow::REVIEW_RETURNED) {
                $trimmed = trim((string) $reason);
                if ($trimmed === '') {
                    throw TeachingAssignmentException::returnReasonRequired();
                }
                if ($own->status === TeachingAssignmentWorkflow::REVIEW_RETURNED
                    && trim((string) $own->reason) === $trimmed) {
                    return $this->loadRequest((int) $current->teaching_assignment_request_id);
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
                if ($own->status === TeachingAssignmentWorkflow::REVIEW_APPROVED) {
                    return $this->loadRequest((int) $current->teaching_assignment_request_id);
                }
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

            $this->refreshAggregateAndMaterialize($user, $current, $reviews);

            return $this->loadRequest((int) $current->teaching_assignment_request_id);
        });
    }

    private function refreshAggregateAndMaterialize(User $user, TeachingAssignmentRequest $request, Collection $reviews): void
    {
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
                $this->materializeEffective($user, $request);
            }

            return;
        }

        $request->status = TeachingAssignmentWorkflow::STATUS_SUBMITTED;
        $request->approved_at = null;
        $request->save();
    }

    private function materializeEffective(User $user, TeachingAssignmentRequest $request): void
    {
        $offering = CourseOffering::query()
            ->whereKey($request->course_offering_id)
            ->lockForUpdate()
            ->firstOrFail();
        $offering->loadMissing('course');

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

    private function createRequest(
        User $user,
        CourseOffering $offering,
        FacultyMember $facultyMember,
        string $role
    ): TeachingAssignmentRequest {
        try {
            $request = new TeachingAssignmentRequest([
                'course_offering_id' => $offering->course_offering_id,
                'faculty_member_id' => $facultyMember->faculty_member_id,
                'instructor_role' => $role,
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
        $this->recordEvent($request, TeachingAssignmentWorkflow::EVENT_SUBMITTED, $user, null);

        return $this->loadRequest((int) $request->teaching_assignment_request_id);
    }

    private function resubmitUnlocked(User $user, TeachingAssignmentRequest $request): TeachingAssignmentRequest
    {
        $reviews = TeachingAssignmentReview::query()
            ->where('teaching_assignment_request_id', $request->teaching_assignment_request_id)
            ->lockForUpdate()
            ->get();

        foreach ($reviews as $review) {
            if ($review->status === TeachingAssignmentWorkflow::REVIEW_RETURNED) {
                $review->status = TeachingAssignmentWorkflow::REVIEW_PENDING;
                $review->reason = null;
                $review->reviewed_by_user_id = null;
                $review->reviewed_at = null;
                $review->save();
            }
        }

        $request->submission_version = (int) $request->submission_version + 1;
        $request->submitted_at = now();
        $request->status = TeachingAssignmentWorkflow::STATUS_SUBMITTED;
        $request->save();
        $this->recordEvent($request, TeachingAssignmentWorkflow::EVENT_RESUBMITTED, $user, null);

        $freshReviews = TeachingAssignmentReview::query()
            ->where('teaching_assignment_request_id', $request->teaching_assignment_request_id)
            ->get()
            ->keyBy(fn (TeachingAssignmentReview $review): string => (string) $review->review_authority);
        $this->refreshAggregateAndMaterialize($user, $request, $freshReviews);

        return $this->loadRequest((int) $request->teaching_assignment_request_id);
    }

    private function replaceUnlocked(
        User $user,
        TeachingAssignmentRequest $current,
        CourseOffering $offering,
        FacultyMember $facultyMember,
        string $role
    ): TeachingAssignmentRequest {
        $current->status = TeachingAssignmentWorkflow::STATUS_SUPERSEDED;
        $current->current_slot = null;
        $current->superseded_at = now();
        $current->save();
        $this->recordEvent($current, TeachingAssignmentWorkflow::EVENT_SUPERSEDED, $user, null);

        $replacement = $this->createRequest($user, $offering, $facultyMember, $role);
        $current->superseded_by_request_id = $replacement->teaching_assignment_request_id;
        $current->save();

        return $replacement;
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

    private function lockCurrentRequest(int $offeringId, string $role): ?TeachingAssignmentRequest
    {
        return TeachingAssignmentRequest::query()
            ->where('course_offering_id', $offeringId)
            ->where('instructor_role', $role)
            ->where('current_slot', 1)
            ->lockForUpdate()
            ->first();
    }

    private function loadRequest(int $id): TeachingAssignmentRequest
    {
        return TeachingAssignmentRequest::query()
            ->with($this->requestDisplayRelations())
            ->findOrFail($id);
    }

    private function assertCanManage(User $user, CourseOffering $offering): void
    {
        if (! $user->hasPermission(TeachingAssignmentWorkflow::PERMISSION_MANAGE)
            && ! $user->hasPermission('teaching_staff.manage')) {
            throw TeachingAssignmentException::offeringOutsideScope();
        }
        $this->assertOfferingInDeanScope($user, $offering);
    }

    private function assertCanView(User $user): void
    {
        if (! $user->hasPermission(TeachingAssignmentWorkflow::PERMISSION_VIEW)
            && ! $user->hasPermission(TeachingAssignmentWorkflow::PERMISSION_MANAGE)
            && ! $user->hasPermission(TeachingAssignmentWorkflow::PERMISSION_REVIEW_SCIENTIFIC)
            && ! $user->hasPermission(TeachingAssignmentWorkflow::PERMISSION_REVIEW_ADMINISTRATIVE)
            && ! $user->hasPermission('teaching_staff.view')
            && ! $user->hasPermission('teaching_staff.manage')) {
            throw TeachingAssignmentException::offeringOutsideScope();
        }
    }

    private function assertScientificReviewer(User $user): void
    {
        if (! $user->hasPermission(TeachingAssignmentWorkflow::PERMISSION_REVIEW_SCIENTIFIC)) {
            throw TeachingAssignmentException::scientificReviewForbidden();
        }
    }

    private function assertAdministrativeReviewer(User $user): void
    {
        if (! $user->hasPermission(TeachingAssignmentWorkflow::PERMISSION_REVIEW_ADMINISTRATIVE)) {
            throw TeachingAssignmentException::administrativeReviewForbidden();
        }
    }

    private function assertOfferingInDeanScope(User $user, CourseOffering $offering): void
    {
        if (! $this->offeringInAccessibleColleges($user, $offering)) {
            throw TeachingAssignmentException::offeringOutsideScope();
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

    private function isDuplicateCurrent(QueryException $exception): bool
    {
        $errorCode = (int) ($exception->errorInfo[1] ?? 0);

        return $errorCode === 1062
            || str_contains($exception->getMessage(), 'uq_tar_current_slot');
    }
}
