<?php

namespace App\Http\Requests\MinistryPlacement;

use Illuminate\Foundation\Http\FormRequest;

class ImportMinistryPlacementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
            'batch_name' => ['required', 'string', 'max:255'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,academic_year_id'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
