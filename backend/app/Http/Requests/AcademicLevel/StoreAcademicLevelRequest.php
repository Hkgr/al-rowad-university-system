<?php

namespace App\Http\Requests\AcademicLevel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAcademicLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'level_code' => 'required|string|max:50|unique:academic_levels,level_code',
            'level_name' => 'required|string|max:100',
            'level_order' => 'required|integer|min:1',
            'is_active' => 'required|boolean',
        ];
    }
}
