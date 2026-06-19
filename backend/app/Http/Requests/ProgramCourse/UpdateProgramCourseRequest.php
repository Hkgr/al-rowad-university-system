<?php

namespace App\Http\Requests\ProgramCourse;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProgramCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $currentProgramCourse = $this->route('program_course');

        return [
            'academic_program_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:academic_programs,academic_program_id',
                Rule::unique('program_courses', 'academic_program_id')
                    ->where(fn ($query) => $query->where('course_id', $this->input('course_id', $currentProgramCourse?->course_id)))
                    ->ignoreModel($this->route('program_course'), 'program_course_id'),
            ],
            'course_id' => 'sometimes|nullable|integer|exists:courses,course_id',
            'academic_level_id' => 'sometimes|nullable|integer|exists:academic_levels,academic_level_id',
            'recommended_semester_id' => 'sometimes|nullable|integer|exists:semesters,semester_id',
            'course_type' => 'sometimes|nullable|string|max:50|in:mandatory,elective',
            'is_active' => 'sometimes|nullable|boolean',
        ];
    }
}
