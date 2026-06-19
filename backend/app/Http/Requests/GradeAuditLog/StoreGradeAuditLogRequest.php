<?php

namespace App\Http\Requests\GradeAuditLog;

use Illuminate\Foundation\Http\FormRequest;

class StoreGradeAuditLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_grade_component_id' => 'required|integer|exists:student_grade_components,student_grade_component_id',
            'old_mark' => 'nullable|numeric',
            'new_mark' => 'nullable|numeric',
            'changed_by_user_id' => 'required|integer|exists:users,user_id',
            'change_reason' => 'required|string',
            'changed_at' => 'nullable|date',
        ];
    }
}
