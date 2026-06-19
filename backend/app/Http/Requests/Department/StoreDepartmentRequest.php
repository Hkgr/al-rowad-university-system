<?php

namespace App\Http\Requests\Department;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'college_id' => 'required|integer|exists:colleges,college_id',
            'organizational_unit_id' => 'nullable|integer|exists:organizational_units,organizational_unit_id|unique:departments,organizational_unit_id',
            'department_code' => 'required|string|max:50|unique:departments,department_code',
            'department_name' => 'required|string|max:200',
            'description' => 'nullable|string',
            'is_active' => 'required|boolean',
        ];
    }
}
