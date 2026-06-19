<?php

namespace App\Http\Requests\EmployeePosition;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => 'sometimes|nullable|integer|exists:employees,employee_id',
            'position_id' => 'sometimes|nullable|integer|exists:positions,position_id',
            'organizational_unit_id' => 'sometimes|nullable|integer|exists:organizational_units,organizational_unit_id',
            'start_date' => 'sometimes|nullable|date',
            'end_date' => 'sometimes|nullable|date',
            'is_primary' => 'sometimes|nullable|integer',
            'is_active' => 'sometimes|nullable|integer',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
