<?php

namespace App\Http\Requests\GradeAuditLog;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGradeAuditLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_grade_component_id' => 'sometimes|nullable|integer|exists:student_grade_components,student_grade_component_id',
            'old_mark' => 'sometimes|nullable|numeric',
            'new_mark' => 'sometimes|nullable|numeric',
            'changed_by_user_id' => 'sometimes|nullable|integer|exists:users,user_id',
            'change_reason' => 'sometimes|nullable|string',
            'changed_at' => 'sometimes|nullable|date',
        ];
    }
}
