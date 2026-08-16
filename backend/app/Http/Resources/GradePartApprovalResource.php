<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GradePartApprovalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->grade_part_approval_id, 'course_offering_id' => $this->course_offering_id,
            'component_type' => $this->component_type, 'status' => $this->status,
            'submission_version' => $this->submission_version, 'submitted_by_user_id' => $this->submitted_by_user_id,
            'submitted_by_name' => $this->userName($this->submittedBy),
            'submitted_at' => $this->submitted_at?->toISOString(), 'reviewed_by_user_id' => $this->reviewed_by_user_id,
            'reviewed_by_name' => $this->userName($this->reviewedBy),
            'reviewed_at' => $this->reviewed_at?->toISOString(), 'review_notes' => $this->review_notes,
            'course_code' => $this->courseOffering?->course?->course_code,
            'course_name' => $this->courseOffering?->course?->course_name,
            'academic_year_name' => $this->courseOffering?->academicYear?->year_name,
            'semester_name' => $this->courseOffering?->semester?->semester_name,
            'section' => [
                'course_offering_id' => $this->course_offering_id,
            ],
            'part_statuses' => [
                'theoretical' => $this->partIndicator('theoretical'),
                'practical' => $this->partIndicator('practical'),
            ],
            'course_offering' => $this->whenLoaded('courseOffering'),
        ];
    }

    /**
     * @return array{required: bool|null, status: string}
     */
    private function partIndicator(string $part): array
    {
        $offering = $this->courseOffering;
        $required = null;
        if ($offering?->relationLoaded('gradeComponents')) {
            $required = $offering->gradeComponents
                ->where('is_required', true)
                ->contains('component_type', $part);
        }

        $approval = null;
        if ($offering?->relationLoaded('gradePartApprovals')) {
            $approval = $offering->gradePartApprovals->firstWhere('component_type', $part);
        } elseif ($this->component_type === $part) {
            $approval = $this->resource;
        }

        if ($required === false) {
            return ['required' => false, 'status' => 'not_required'];
        }

        return [
            'required' => $required,
            'status' => $approval?->status ?? 'draft',
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
