<?php

namespace App\Http\Requests\EmployeeType;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type_code' => 'sometimes|nullable|string|max:50',
            'type_name' => 'sometimes|nullable|string|max:100',
            'description' => 'sometimes|nullable|string|max:255',
            'is_active' => 'sometimes|nullable|integer',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
