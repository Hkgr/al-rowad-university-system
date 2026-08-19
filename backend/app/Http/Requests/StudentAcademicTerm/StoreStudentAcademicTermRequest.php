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
            'academic_level_id' => 'prohibited',
            'term_gpa' => 'prohibited',
            'cumulative_gpa' => 'prohibited',
            'total_registered_hours' => 'prohibited',
            'earned_hours' => 'prohibited',
            'attempted_hours' => 'prohibited',
            'is_finalized' => 'prohibited',
            'finalized_at' => 'prohibited',
            'finalized_by_user_id' => 'prohibited',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
