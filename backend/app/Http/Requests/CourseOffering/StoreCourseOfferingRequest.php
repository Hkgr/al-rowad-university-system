<?php

namespace App\Http\Requests\CourseOffering;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCourseOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_id' => 'required|integer|exists:courses,course_id',
            'academic_year_id' => 'required|integer|exists:academic_years,academic_year_id',
            'semester_id' => 'required|integer|exists:semesters,semester_id',
            'department_id' => 'nullable|integer|exists:departments,department_id',
            'academic_program_id' => 'nullable|integer|exists:academic_programs,academic_program_id',
            'faculty_member_id' => 'nullable|integer|exists:faculty_members,faculty_member_id',
            'capacity' => 'required|integer|min:1',
            'available_seats' => 'required|integer|min:0|lte:capacity',
            'status' => 'required|string|max:50',
        ];
    }
}
