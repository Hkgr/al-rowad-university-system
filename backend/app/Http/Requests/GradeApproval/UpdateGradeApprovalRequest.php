<?php

namespace App\Http\Requests\GradeApproval;

use App\Models\ApprovalStatus;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGradeApprovalRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('approval_status_code')) {
            $id = ApprovalStatus::query()->where('status_code', $this->string('approval_status_code')->toString())->value('approval_status_id');
            if ($id !== null) {
                $this->merge(['approval_status_id' => $id]);
            }
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_offering_id' => 'sometimes|nullable|integer|exists:course_offerings,course_offering_id',
            'approval_status_id' => 'sometimes|nullable|integer|exists:approval_statuses,approval_status_id',
            'approval_status_code' => 'sometimes|required|string|exists:approval_statuses,status_code',
            'submitted_by_user_id' => 'prohibited',
            'submitted_at' => 'sometimes|nullable|date',
            'approved_by_user_id' => 'prohibited',
            'approval_role' => 'sometimes|nullable|string|max:100',
            'approval_date' => 'sometimes|nullable|date',
            'approval_notes' => 'sometimes|nullable|string',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
