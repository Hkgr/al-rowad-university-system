<?php

namespace App\Http\Requests\MinistryPlacement;

use App\Support\MinistryPlacementAccess;
use Illuminate\Foundation\Http\FormRequest;

class MatchMinistryPlacementProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(MinistryPlacementAccess::class)->canManage($this->user());
    }

    public function rules(): array
    {
        return [
            'academic_program_id' => ['required', 'integer'],
        ];
    }
}
