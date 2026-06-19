<?php

namespace App\Http\Requests\GradeApproval;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGradeApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_offering_id' => 'sometimes|nullable|integer|exists:course_offerings,course_offering_id',
            'approval_status_id' => 'sometimes|nullable|integer|exists:approval_statuses,approval_status_id',
            'submitted_by_user_id' => 'sometimes|nullable|integer|exists:users,user_id',
            'submitted_at' => 'sometimes|nullable|date',
            'approved_by_user_id' => 'sometimes|nullable|integer|exists:users,user_id',
            'approval_role' => 'sometimes|nullable|string|max:100',
            'approval_date' => 'sometimes|nullable|date',
            'approval_notes' => 'sometimes|nullable|string',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
