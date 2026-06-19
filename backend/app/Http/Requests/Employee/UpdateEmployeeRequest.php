<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_number' => 'sometimes|nullable|string|max:50',
            'first_name' => 'sometimes|nullable|string|max:100',
            'last_name' => 'sometimes|nullable|string|max:100',
            'father_name' => 'sometimes|nullable|string|max:100',
            'mother_name' => 'sometimes|nullable|string|max:100',
            'phone_number' => 'sometimes|nullable|string|max:30',
            'email' => 'sometimes|nullable|string|max:150',
            'hire_date' => 'sometimes|nullable|date',
            'employee_type_id' => 'sometimes|nullable|integer|exists:employee_types,employee_type_id',
            'employee_status_id' => 'sometimes|nullable|integer|exists:employee_statuses,employee_status_id',
            'organizational_unit_id' => 'sometimes|nullable|integer|exists:organizational_units,organizational_unit_id',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
