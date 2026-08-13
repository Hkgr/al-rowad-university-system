<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\GradeApproval */
class GradeApprovalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'grade_approval_id' => $this->grade_approval_id,
            'status_code' => $this->whenLoaded('approvalStatus', fn () => $this->approvalStatus?->status_code),
            'status_name' => $this->whenLoaded('approvalStatus', fn () => $this->approvalStatus?->status_name),
            'course_offering_id' => $this->course_offering_id,
            'course_id' => $this->courseOffering?->course_id,
            'course_code' => $this->courseOffering?->course?->course_code,
            'course_name' => $this->courseOffering?->course?->course_name,
            'department_id' => $this->courseOffering?->department_id,
            'department_name' => $this->courseOffering?->department?->department_name,
            'academic_year_id' => $this->courseOffering?->academic_year_id,
            'academic_year_name' => $this->courseOffering?->academicYear?->year_name,
            'semester_id' => $this->courseOffering?->semester_id,
            'semester_name' => $this->courseOffering?->semester?->semester_name,
            'submitted_by_user_id' => $this->submitted_by_user_id,
            'submitted_by_name' => $this->userName($this->submittedBy),
            'submitted_at' => $this->submitted_at,
            'reviewed_by_user_id' => $this->approved_by_user_id,
            'reviewed_by_name' => $this->userName($this->approvedBy),
            'approval_role' => $this->approval_role,
            'review_date' => $this->approval_date,
            'approval_notes' => $this->approval_notes,
            'eligible_students_count' => $this->eligible_students_count,
            'completed_students_count' => $this->completed_students_count,
            'incomplete_students_count' => $this->incomplete_students_count,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function userName($user): ?string
    {
        if ($user?->employee) {
            return trim($user->employee->first_name.' '.$user->employee->last_name);
        }

        return $user?->username;
    }
}
