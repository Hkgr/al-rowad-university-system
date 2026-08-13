<?php

namespace App\Services;

use App\Exceptions\GradeException;
use App\Models\ApprovalStatus;
use App\Models\CourseOffering;
use App\Models\GradeApproval;
use App\Models\StudentCourseRegistration;
use App\Models\StudentCourseResult;
use App\Models\StudentGradeComponent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class GradeWorkflowService
{
    public function getWorkflow(int $courseOfferingId): array
    {
        CourseOffering::query()->findOrFail($courseOfferingId);

        return $this->buildWorkflow($courseOfferingId);
    }

    public function submit(int $courseOfferingId, int $userId): array
    {
        return DB::transaction(function () use ($courseOfferingId, $userId): array {
            CourseOffering::query()->whereKey($courseOfferingId)->lockForUpdate()->firstOrFail();

            $approval = GradeApproval::query()
                ->where('course_offering_id', $courseOfferingId)
                ->orderByDesc('grade_approval_id')
                ->lockForUpdate()
                ->first();
            $approval?->load('approvalStatus');

            if ($approval !== null && ! $approval->allowsGradeEditing()) {
                throw new GradeException(
                    'Grades have already been submitted and cannot be submitted again.',
                    status: 409,
                    errorCode: 'grades_locked'
                );
            }

            $registrations = StudentCourseRegistration::query()
                ->where('course_offering_id', $courseOfferingId)
                ->current()
                ->orderBy('student_course_registration_id')
                ->lockForUpdate()
                ->get();

            if ($registrations->isEmpty()) {
                throw new GradeException(
                    'This section has no eligible registered students.',
                    status: 409,
                    errorCode: 'no_eligible_students'
                );
            }

            $registrationIds = $registrations->pluck('student_course_registration_id');
            $results = StudentCourseResult::query()
                ->whereIn('student_course_registration_id', $registrationIds)
                ->with('resultStatus')
                ->lockForUpdate()
                ->get()
                ->keyBy('student_course_registration_id');

            $incomplete = $this->incompleteRegistrations($registrations, $results);

            if ($incomplete->isNotEmpty()) {
                throw new GradeException(
                    'All eligible students must have valid theoretical and practical marks before submission.',
                    ['registration_ids' => $incomplete->pluck('student_course_registration_id')->values()->all()],
                    409,
                    'grade_sheet_incomplete'
                );
            }

            $pendingStatusId = ApprovalStatus::query()
                ->where('status_code', 'pending')
                ->where('is_active', true)
                ->value('approval_status_id');

            if ($pendingStatusId === null) {
                throw new GradeException('The pending grade approval status is not configured.', status: 409, errorCode: 'grade_approval_status_missing');
            }

            $values = [
                'approval_status_id' => $pendingStatusId,
                'submitted_by_user_id' => $userId,
                'submitted_at' => now(),
                'approved_by_user_id' => null,
                'approval_date' => null,
                'approval_role' => null,
                'approval_notes' => null,
            ];

            if ($approval === null) {
                $approval = GradeApproval::query()->create(['course_offering_id' => $courseOfferingId] + $values);
            } else {
                $approval->update($values);
            }

            StudentGradeComponent::query()
                ->whereIn('student_course_registration_id', $registrationIds)
                ->lockForUpdate()
                ->update(['grade_status' => 'submitted']);

            return $this->buildWorkflow($courseOfferingId, $approval->fresh('approvalStatus'));
        });
    }

    private function buildWorkflow(int $courseOfferingId, ?GradeApproval $approval = null): array
    {
        $approval ??= GradeApproval::query()
            ->where('course_offering_id', $courseOfferingId)
            ->with('approvalStatus')
            ->orderByDesc('grade_approval_id')
            ->first();

        $status = $approval?->workflowStatus() ?? 'draft';

        $registrations = StudentCourseRegistration::query()
            ->where('course_offering_id', $courseOfferingId)
            ->current()
            ->with('studentCourseResult.resultStatus')
            ->get();
        $completed = $this->completedRegistrations($registrations)->count();
        $editable = $approval === null || $approval->allowsGradeEditing();

        return [
            'course_offering_id' => $courseOfferingId,
            'status' => $status,
            'editable' => $editable,
            'can_submit' => $editable && $registrations->isNotEmpty() && $completed === $registrations->count(),
            'approval_id' => $approval?->grade_approval_id,
            'submitted_at' => $approval?->submitted_at?->toISOString(),
            'approval_notes' => $approval?->approval_notes,
            'eligible_students_count' => $registrations->count(),
            'completed_students_count' => $completed,
            'incomplete_students_count' => $registrations->count() - $completed,
        ];
    }

    public function completedRegistrations(Collection $registrations): Collection
    {
        return $registrations->filter(fn (StudentCourseRegistration $registration): bool =>
            $this->resultIsComplete($registration->studentCourseResult));
    }

    public function incompleteRegistrations(Collection $registrations, ?Collection $results = null): Collection
    {
        return $registrations->filter(function (StudentCourseRegistration $registration) use ($results): bool {
            $result = $results?->get($registration->student_course_registration_id)
                ?? $registration->studentCourseResult;

            return ! $this->resultIsComplete($result);
        });
    }

    private function resultIsComplete(?StudentCourseResult $result): bool
    {
        return $result !== null && (
            $result->is_deprived
            || $result->resultStatus?->status_code === 'deprived'
            || ($result->theoretical_total !== null
                && (float) $result->theoretical_total >= 0
                && (float) $result->theoretical_total <= 60
                && $result->practical_total !== null
                && (float) $result->practical_total >= 0
                && (float) $result->practical_total <= 40)
        );
    }
}
