<?php

namespace App\Http\Requests\Grade;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRegistrationGradesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'theoretical_mark' => ['required', 'numeric', 'min:0', 'max:60'],
            'practical_mark' => ['required', 'numeric', 'min:0', 'max:40'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
