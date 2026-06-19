<?php

namespace App\Http\Requests\CourseOffering;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCourseOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_id' => 'sometimes|nullable|integer|exists:courses,course_id',
            'academic_year_id' => 'sometimes|nullable|integer|exists:academic_years,academic_year_id',
            'semester_id' => 'sometimes|nullable|integer|exists:semesters,semester_id',
            'department_id' => 'sometimes|nullable|integer|exists:departments,department_id',
            'academic_program_id' => 'sometimes|nullable|integer|exists:academic_programs,academic_program_id',
            'faculty_member_id' => 'sometimes|nullable|integer|exists:faculty_members,faculty_member_id',
            'capacity' => 'sometimes|nullable|integer|min:1',
            'available_seats' => 'sometimes|nullable|integer|min:0',
            'status' => 'sometimes|nullable|string|max:50',
        ];
    }
}
