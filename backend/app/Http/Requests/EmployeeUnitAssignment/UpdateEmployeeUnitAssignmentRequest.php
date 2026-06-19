<?php

namespace App\Http\Requests\EmployeeUnitAssignment;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeUnitAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => 'sometimes|nullable|integer|exists:employees,employee_id',
            'organizational_unit_id' => 'sometimes|nullable|integer|exists:organizational_units,organizational_unit_id',
            'start_date' => 'sometimes|nullable|date',
            'end_date' => 'sometimes|nullable|date',
            'assignment_notes' => 'sometimes|nullable|string|max:255',
            'is_active' => 'sometimes|nullable|integer',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
