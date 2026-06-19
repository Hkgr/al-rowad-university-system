<?php

namespace App\Http\Requests\StudentAcademicTerm;

use Illuminate\Foundation\Http\FormRequest;

class StoreStudentAcademicTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => 'required|integer|exists:students,student_id',
            'academic_year_id' => 'required|integer|exists:academic_years,academic_year_id',
            'semester_id' => 'required|integer|exists:semesters,semester_id',
            'academic_level_id' => 'required|integer|exists:academic_levels,academic_level_id',
            'term_gpa' => 'nullable|numeric',
            'cumulative_gpa' => 'nullable|numeric',
            'total_registered_hours' => 'required|integer',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
