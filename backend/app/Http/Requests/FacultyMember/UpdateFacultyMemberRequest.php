<?php

namespace App\Http\Requests\FacultyMember;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFacultyMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => 'sometimes|nullable|integer|exists:employees,employee_id',
            'academic_rank' => 'sometimes|nullable|string|max:100',
            'specialization' => 'sometimes|nullable|string|max:200',
            'office_location' => 'sometimes|nullable|string|max:150',
            'is_active' => 'sometimes|nullable|integer',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
