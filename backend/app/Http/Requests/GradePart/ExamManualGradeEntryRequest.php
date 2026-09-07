<?php

namespace App\Http\Requests\GradePart;

use App\Support\ExamManualGradeEntryAccess;
use Illuminate\Foundation\Http\FormRequest;

class ExamManualGradeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null) return false;
        app(ExamManualGradeEntryAccess::class)->authorize($this->user());
        return true;
    }

    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'students' => ['q' => ['required', 'string', 'min:1', 'max:150']] + $this->pagination(),
            'catalog' => ['q' => ['sometimes', 'nullable', 'string', 'max:150'], 'academic_year_id' => ['sometimes', 'integer', 'min:1'], 'semester_id' => ['sometimes', 'integer', 'min:1']] + $this->pagination(),
            'prepareComponents' => ['revision' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'confirmed' => ['required', 'boolean', 'accepted']],
            'prepareRegistration' => ['confirmed' => ['required', 'boolean', 'accepted'], 'reason' => ['required', 'string', 'max:1000', 'regex:/\\S/u'],
                'course_id' => ['required', 'integer', 'min:1'], 'academic_year_id' => ['required', 'integer', 'min:1'], 'semester_id' => ['required', 'integer', 'min:1']],
            'registrations' => ['academic_year_id' => ['sometimes', 'integer', 'min:1'], 'semester_id' => ['sometimes', 'integer', 'min:1']] + $this->pagination(),
            'save' => [
                'revision' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
                'acknowledged' => ['required', 'boolean', 'accepted'],
                'correction_confirmed' => ['sometimes', 'boolean'],
                'correction_reason' => ['nullable', 'string', 'max:1000'],
                'components' => ['required', 'array', 'min:1', 'max:100'],
                'components.*' => ['required', 'array:grade_component_id,mark'],
                'components.*.grade_component_id' => ['required', 'integer', 'min:1', 'distinct'],
                'components.*.mark' => ['present', 'nullable', 'numeric', 'min:0', 'regex:/^\d+(?:\.\d{1,2})?$/D'],
            ],
            'submit' => ['revision' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'confirmed' => ['required', 'boolean', 'accepted']],
            default => [],
        };
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $allowed = array_filter(array_keys($this->rules()), fn ($key) => ! str_contains($key, '.'));
            foreach (array_diff(array_keys($this->all()), $allowed) as $key) $validator->errors()->add($key, 'حقل غير مسموح.');
        });
    }

    private function pagination(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
