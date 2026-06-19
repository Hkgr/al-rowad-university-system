<?php

namespace App\Http\Requests\EmployeePosition;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => 'required|integer|exists:employees,employee_id',
            'position_id' => 'required|integer|exists:positions,position_id',
            'organizational_unit_id' => 'nullable|integer|exists:organizational_units,organizational_unit_id',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date',
            'is_primary' => 'required|integer',
            'is_active' => 'required|integer',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
