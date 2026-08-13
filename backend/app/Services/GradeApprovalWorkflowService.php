<?php

namespace App\Services;

use App\Exceptions\GradeException;
use App\Models\ApprovalStatus;
use App\Models\CourseOffering;
use App\Models\GradeApproval;
use App\Models\StudentCourseRegistration;
use App\Models\StudentCourseResult;
use App\Models\StudentGradeComponent;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GradeApprovalWorkflowService
{
    public function __construct(
        private readonly DataScopeService $dataScope,
        private readonly GradeWorkflowService $workflow,
        private readonly GradeService $grades,
    ) {}

    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = $this->baseQuery($user)
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) =>
                $q->whereHas('approvalStatus', fn (Builder $s) => $s->where('status_code', $status)))
            ->when($filters['academic_year_id'] ?? null, fn (Builder $q, int $id) =>
                $q->whereHas('courseOffering', fn (Builder $o) => $o->where('academic_year_id', $id)))
            ->when($filters['semester_id'] ?? null, fn (Builder $q, int $id) =>
                $q->whereHas('courseOffering', fn (Builder $o) => $o->where('semester_id', $id)))
            ->when($filters['department_id'] ?? null, fn (Builder $q, int $id) =>
                $q->whereHas('courseOffering', fn (Builder $o) => $o->where('department_id', $id)))
            ->when($filters['course_offering_id'] ?? null, fn (Builder $q, int $id) => $q->where('course_offering_id', $id))
            ->orderByRaw("CASE WHEN EXISTS (SELECT 1 FROM approval_statuses s WHERE s.approval_status_id = grade_approvals.approval_status_id AND s.status_code = 'pending') THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN EXISTS (SELECT 1 FROM approval_statuses s WHERE s.approval_status_id = grade_approvals.approval_status_id AND s.status_code = 'pending') THEN submitted_at END ASC")
            ->orderByDesc('updated_at');

        $paginator = $query->paginate($filters['per_page'] ?? 15);
        $this->attachCompletionCounts($paginator->getCollection());

        return $paginator;
    }

    public function find(User $user, int $id): GradeApproval
    {
        $approval = $this->baseQuery($user)->find($id);
        if ($approval === null && GradeApproval::query()->whereKey($id)->exists()) {
            throw new GradeException(
                'You are not authorized to access this grade approval.',
                status: 403,
                errorCode: 'grade_approval_out_of_scope'
            );
        }
        $approval ??= GradeApproval::query()->findOrFail($id);
        $this->attachCompletionCounts(collect([$approval]));

        return $approval;
    }

    public function details(User $user, int $id): array
    {
        $approval = $this->find($user, $id);

        return [
            'approval' => $approval,
            'workflow' => $this->workflow->getWorkflow((int) $approval->course_offering_id),
            'grade_sheet' => $this->grades->getGradeSheet((int) $approval->course_offering_id),
        ];
    }

    public function approve(User $user, int $id, ?string $notes): GradeApproval
    {
        return $this->transition($user, $id, 'approved', $notes);
    }

    public function returnForCorrection(User $user, int $id, string $notes): GradeApproval
    {
        return $this->transition($user, $id, 'returned_for_correction', $notes);
    }

    private function transition(User $user, int $id, string $targetStatus, ?string $notes): GradeApproval
    {
        $visible = $this->find($user, $id);

        return DB::transaction(function () use ($user, $visible, $targetStatus, $notes): GradeApproval {
            CourseOffering::query()->whereKey($visible->course_offering_id)->lockForUpdate()->firstOrFail();
            $approval = GradeApproval::query()
                ->whereKey($visible->grade_approval_id)
                ->where('course_offering_id', $visible->course_offering_id)
                ->lockForUpdate()->firstOrFail();
            $approval->load('approvalStatus');

            if ($approval->workflowStatus() !== 'pending') {
                throw new GradeException(
                    'Only a pending grade approval may be reviewed.',
                    status: 409,
                    errorCode: 'grade_approval_not_pending'
                );
            }

            $registrations = StudentCourseRegistration::query()
                ->where('course_offering_id', $visible->course_offering_id)
                ->current()->orderBy('student_course_registration_id')->lockForUpdate()->get();
            if ($registrations->isEmpty()) {
                throw new GradeException('This section has no eligible registered students.', status: 409, errorCode: 'no_eligible_students');
            }

            $registrationIds = $registrations->pluck('student_course_registration_id');
            $results = StudentCourseResult::query()->whereIn('student_course_registration_id', $registrationIds)
                ->with('resultStatus')->lockForUpdate()->get()->keyBy('student_course_registration_id');

            if ($targetStatus === 'approved') {
                $incomplete = $this->workflow->incompleteRegistrations($registrations, $results);
                if ($incomplete->isNotEmpty()) {
                    throw new GradeException(
                        'The grade sheet is incomplete or contains marks outside the allowed limits.',
                        ['registration_ids' => $incomplete->pluck('student_course_registration_id')->values()->all()],
                        409,
                        'grade_sheet_incomplete'
                    );
                }
            }

            $statusId = ApprovalStatus::query()->where('status_code', $targetStatus)->where('is_active', true)
                ->value('approval_status_id');
            if ($statusId === null) {
                throw new GradeException('The requested grade approval status is not configured.', status: 409, errorCode: 'grade_approval_status_missing');
            }

            StudentGradeComponent::query()->whereIn('student_course_registration_id', $registrationIds)
                ->lockForUpdate()->update(['grade_status' => $targetStatus === 'approved' ? 'approved' : 'draft']);
            $approval->update([
                'approval_status_id' => $statusId,
                'approved_by_user_id' => $user->user_id,
                'approval_date' => now(),
                'approval_role' => 'examination_committee',
                'approval_notes' => $notes,
            ]);

            return $this->find($user, $approval->grade_approval_id);
        });
    }

    private function baseQuery(User $user): Builder
    {
        return $this->dataScope->scopeResourceQuery(GradeApproval::query(), $user)->with([
            'approvalStatus', 'submittedBy.employee', 'approvedBy.employee',
            'courseOffering.course', 'courseOffering.department',
            'courseOffering.academicYear', 'courseOffering.semester',
        ]);
    }

    private function attachCompletionCounts(Collection $approvals): void
    {
        $offeringIds = $approvals->pluck('course_offering_id')->unique()->values();
        if ($offeringIds->isEmpty()) return;

        $registrations = StudentCourseRegistration::query()->whereIn('course_offering_id', $offeringIds)
            ->current()->with('studentCourseResult.resultStatus')->get()->groupBy('course_offering_id');
        foreach ($approvals as $approval) {
            $eligible = $registrations->get($approval->course_offering_id, collect());
            $completed = $this->workflow->completedRegistrations($eligible)->count();
            $approval->setAttribute('eligible_students_count', $eligible->count());
            $approval->setAttribute('completed_students_count', $completed);
            $approval->setAttribute('incomplete_students_count', $eligible->count() - $completed);
        }
    }
}
