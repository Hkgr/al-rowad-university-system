<?php

namespace App\Http\Requests\Semester;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSemesterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'semester_code' => 'required|string|max:50|unique:semesters,semester_code',
            'semester_name' => 'required|string|max:100',
            'semester_order' => 'required|integer|min:1',
            'is_active' => 'required|boolean',
        ];
    }
}
