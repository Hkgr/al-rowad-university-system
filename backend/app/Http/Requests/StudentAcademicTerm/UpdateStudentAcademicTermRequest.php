<?php

namespace App\Http\Requests\StudentAcademicTerm;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentAcademicTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => 'sometimes|nullable|integer|exists:students,student_id',
            'academic_year_id' => 'sometimes|nullable|integer|exists:academic_years,academic_year_id',
            'semester_id' => 'sometimes|nullable|integer|exists:semesters,semester_id',
            'academic_level_id' => 'sometimes|nullable|integer|exists:academic_levels,academic_level_id',
            'term_gpa' => 'sometimes|nullable|numeric',
            'cumulative_gpa' => 'sometimes|nullable|numeric',
            'total_registered_hours' => 'sometimes|nullable|integer',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
