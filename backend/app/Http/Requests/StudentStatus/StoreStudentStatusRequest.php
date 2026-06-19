<?php

namespace App\Http\Requests\StudentStatus;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status_code' => 'required|string|max:50|unique:student_statuses,status_code',
            'status_name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'is_active' => 'required|boolean',
        ];
    }
}
