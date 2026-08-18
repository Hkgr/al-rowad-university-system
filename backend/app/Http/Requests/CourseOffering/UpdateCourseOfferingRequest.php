<?php

namespace App\Http\Requests\CourseOffering;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCourseOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_id' => 'sometimes|integer|exists:courses,course_id',
            'academic_year_id' => 'sometimes|integer|exists:academic_years,academic_year_id',
            'semester_id' => 'sometimes|integer|exists:semesters,semester_id',
            'department_id' => 'sometimes|integer|exists:departments,department_id',
            'academic_program_id' => 'sometimes|integer|exists:academic_programs,academic_program_id',
            'capacity' => 'sometimes|nullable|integer|min:1',
            'available_seats' => 'sometimes|nullable|integer|min:0',
            'status' => 'sometimes|nullable|string|max:50',
            'exceptional' => 'prohibited',
            'force' => 'prohibited',
            'skip_coverage' => 'prohibited',
            'bypass' => 'prohibited',
        ];
    }
}
