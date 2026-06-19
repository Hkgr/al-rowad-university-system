<?php

namespace App\Http\Requests\ProgramCourse;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProgramCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_program_id' => [
                'required',
                'integer',
                'exists:academic_programs,academic_program_id',
                Rule::unique('program_courses', 'academic_program_id')->where(fn ($query) => $query->where('course_id', $this->input('course_id'))),
            ],
            'course_id' => 'required|integer|exists:courses,course_id',
            'academic_level_id' => 'required|integer|exists:academic_levels,academic_level_id',
            'recommended_semester_id' => 'required|integer|exists:semesters,semester_id',
            'course_type' => 'required|string|max:50|in:mandatory,elective',
            'is_active' => 'required|boolean',
        ];
    }
}
