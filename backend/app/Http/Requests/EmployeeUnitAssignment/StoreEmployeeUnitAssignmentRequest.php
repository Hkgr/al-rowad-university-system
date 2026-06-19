<?php

namespace App\Http\Requests\EmployeeUnitAssignment;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeUnitAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => 'required|integer|exists:employees,employee_id',
            'organizational_unit_id' => 'required|integer|exists:organizational_units,organizational_unit_id',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date',
            'assignment_notes' => 'nullable|string|max:255',
            'is_active' => 'required|integer',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
