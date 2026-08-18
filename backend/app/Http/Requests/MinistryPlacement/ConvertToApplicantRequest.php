<?php

namespace App\Http\Requests\MinistryPlacement;

use Illuminate\Foundation\Http\FormRequest;

class ConvertToApplicantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
