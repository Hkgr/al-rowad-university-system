<?php

namespace App\Http\Requests\FacultyMember;

use Illuminate\Foundation\Http\FormRequest;

class StoreFacultyMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => 'required|integer|exists:employees,employee_id',
            'academic_rank' => 'nullable|string|max:100',
            'specialization' => 'nullable|string|max:200',
            'office_location' => 'nullable|string|max:150',
            'is_active' => 'required|integer',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
