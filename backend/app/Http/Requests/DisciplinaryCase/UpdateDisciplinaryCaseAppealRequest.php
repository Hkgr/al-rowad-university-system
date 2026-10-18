<?php

namespace App\Http\Requests\DisciplinaryCase;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDisciplinaryCaseAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status_code' => 'required|string|in:accepted,rejected',
            'notes' => 'nullable|string',
        ];
    }
}
