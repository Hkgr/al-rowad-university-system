<?php

namespace App\Http\Requests\MinistryPlacement;

use App\Support\MinistryPlacementAccess;
use Illuminate\Foundation\Http\FormRequest;

class ApplyMinistryPlacementProgramGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(MinistryPlacementAccess::class)->canManage($this->user());
    }

    public function rules(): array
    {
        return [
            'preference_key' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'academic_program_id' => ['required', 'integer'],
            'expected_eligible_count' => ['required', 'integer', 'min:0'],
        ];
    }
}
