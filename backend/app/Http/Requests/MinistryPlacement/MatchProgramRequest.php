<?php

namespace App\Http\Requests\MinistryPlacement;

use Illuminate\Foundation\Http\FormRequest;

class MatchProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_program_id' => ['required', 'integer', 'exists:academic_programs,academic_program_id'],
        ];
    }
}
