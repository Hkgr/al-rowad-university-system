<?php

namespace App\Http\Requests\Dean;

use Illuminate\Foundation\Http\FormRequest;

class OpenDeanRegistrationOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'program_course_id' => ['required', 'integer', 'min:1', 'exists:program_courses,program_course_id'],
            'academic_year_id' => ['required', 'integer', 'min:1', 'exists:academic_years,academic_year_id'],
            'semester_id' => ['required', 'integer', 'min:1', 'exists:semesters,semester_id'],
            'capacity' => ['sometimes', 'integer', 'min:1'],
            'college_id' => ['prohibited'],
            'course_id' => ['prohibited'],
            'academic_program_id' => ['prohibited'],
            'department_id' => ['prohibited'],
            'faculty_member_id' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
