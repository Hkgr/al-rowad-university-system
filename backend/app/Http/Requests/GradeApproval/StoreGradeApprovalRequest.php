<?php

namespace App\Http\Requests\GradeApproval;

use Illuminate\Foundation\Http\FormRequest;

class StoreGradeApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_offering_id' => 'required|integer|exists:course_offerings,course_offering_id',
            'approval_status_id' => 'required|integer|exists:approval_statuses,approval_status_id',
            'submitted_by_user_id' => 'required|integer|exists:users,user_id',
            'submitted_at' => 'nullable|date',
            'approved_by_user_id' => 'nullable|integer|exists:users,user_id',
            'approval_role' => 'nullable|string|max:100',
            'approval_date' => 'nullable|date',
            'approval_notes' => 'nullable|string',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
