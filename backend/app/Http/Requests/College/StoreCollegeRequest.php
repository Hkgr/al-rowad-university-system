<?php

namespace App\Http\Requests\College;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCollegeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organizational_unit_id' => 'nullable|integer|exists:organizational_units,organizational_unit_id|unique:colleges,organizational_unit_id',
            'college_code' => 'required|string|max:50|unique:colleges,college_code',
            'college_name' => 'required|string|max:200',
            'description' => 'nullable|string',
            'is_active' => 'required|boolean',
        ];
    }
}
