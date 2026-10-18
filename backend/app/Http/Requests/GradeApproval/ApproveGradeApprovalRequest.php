<?php

namespace App\Http\Requests\GradeApproval;

use Illuminate\Foundation\Http\FormRequest;

class ApproveGradeApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('exams.manage') === true;
    }

    public function rules(): array
    {
        return [
            'approval_notes' => ['nullable', 'string', 'max:2000'],
            'approval_status_id' => ['prohibited'], 'approval_status_code' => ['prohibited'],
            'submitted_by_user_id' => ['prohibited'], 'submitted_at' => ['prohibited'],
            'approved_by_user_id' => ['prohibited'], 'approval_date' => ['prohibited'],
            'course_offering_id' => ['prohibited'],
        ];
    }
}
