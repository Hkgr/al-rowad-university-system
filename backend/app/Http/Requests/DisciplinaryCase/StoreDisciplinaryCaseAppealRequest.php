<?php

namespace App\Http\Requests\DisciplinaryCase;

use Illuminate\Foundation\Http\FormRequest;

class StoreDisciplinaryCaseAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'case_id' => 'required|integer|exists:student_disciplinary_cases,case_id',
            'appeal_reason' => 'required|string',
        ];
    }
}
