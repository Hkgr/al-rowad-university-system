<?php

namespace App\Http\Requests\CoursePrerequisite;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCoursePrerequisiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $currentCoursePrerequisite = $this->route('course_prerequisite');

        return [
            'course_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:courses,course_id',
                Rule::unique('course_prerequisites', 'course_id')
                    ->where(fn ($query) => $query->where('prerequisite_course_id', $this->input('prerequisite_course_id', $currentCoursePrerequisite?->prerequisite_course_id)))
                    ->ignoreModel($this->route('course_prerequisite'), 'course_prerequisite_id'),
            ],
            'prerequisite_course_id' => 'sometimes|nullable|integer|exists:courses,course_id|different:course_id',
            'minimum_result_status_id' => 'sometimes|nullable|integer|exists:result_statuses,result_status_id',
        ];
    }
}
