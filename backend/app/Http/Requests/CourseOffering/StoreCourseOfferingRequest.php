<?php

namespace App\Http\Requests\CourseOffering;

use App\Exceptions\TeachingAssignmentException;
use Illuminate\Foundation\Http\FormRequest;

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
            'academic_program_id' => 'required|integer|exists:academic_programs,academic_program_id',
            'department_id' => 'sometimes|nullable|integer|exists:departments,department_id',
            'capacity' => 'required|integer|min:1',
            'available_seats' => 'required|integer|min:0|lte:capacity',
            'status' => 'required|string|max:50',
            'exceptional' => 'prohibited',
            'force' => 'prohibited',
            'skip_coverage' => 'prohibited',
            'bypass' => 'prohibited',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function (): void {
            if (! $this->filled('faculty_member_id')) {
                return;
            }

            throw TeachingAssignmentException::facultyMemberAssignmentWorkflowRequired();
        });
    }
}
