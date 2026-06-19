<?php

namespace App\Http\Requests\Department;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'college_id' => 'sometimes|nullable|integer|exists:colleges,college_id',
            'organizational_unit_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:organizational_units,organizational_unit_id',
                Rule::unique('departments', 'organizational_unit_id')->ignoreModel($this->route('department'), 'department_id'),
            ],
            'department_code' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('departments', 'department_code')->ignoreModel($this->route('department'), 'department_id'),
            ],
            'department_name' => 'sometimes|nullable|string|max:200',
            'description' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|nullable|boolean',
        ];
    }
}
