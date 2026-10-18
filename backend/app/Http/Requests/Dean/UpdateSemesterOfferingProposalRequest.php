<?php

namespace App\Http\Requests\Dean;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSemesterOfferingProposalRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'is_selected' => ['sometimes', 'boolean'],
            'minimum_enrollment' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['prohibited'],
            'submission_version' => ['prohibited'],
            'materialized_at' => ['prohibited'],
        ];
    }
}
