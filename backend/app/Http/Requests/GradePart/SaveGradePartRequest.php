<?php

namespace App\Http\Requests\GradePart;

use Illuminate\Foundation\Http\FormRequest;

class SaveGradePartRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasPermission('grades.manage') === true; }
    public function rules(): array
    {
        return [
            'mark' => ['required_without:components', 'nullable', 'numeric', 'min:0'],
            'components' => ['required_without:mark', 'array', 'min:1'],
            'components.*.grade_component_id' => ['required', 'integer', 'distinct'],
            'components.*.mark' => ['present', 'nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
            'entered_by_user_id' => ['prohibited'],
        ];
    }
}
