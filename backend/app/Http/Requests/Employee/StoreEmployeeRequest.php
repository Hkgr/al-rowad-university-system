<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_number' => 'required|string|max:50',
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'father_name' => 'nullable|string|max:100',
            'mother_name' => 'nullable|string|max:100',
            'phone_number' => 'nullable|string|max:30',
            'email' => 'nullable|string|max:150',
            'hire_date' => 'nullable|date',
            'employee_type_id' => 'required|integer|exists:employee_types,employee_type_id',
            'employee_status_id' => 'required|integer|exists:employee_statuses,employee_status_id',
            'organizational_unit_id' => 'nullable|integer|exists:organizational_units,organizational_unit_id',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
