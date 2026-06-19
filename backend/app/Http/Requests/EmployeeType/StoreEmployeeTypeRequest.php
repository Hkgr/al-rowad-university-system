<?php

namespace App\Http\Requests\EmployeeType;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type_code' => 'required|string|max:50',
            'type_name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'is_active' => 'required|integer',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
