<?php

namespace App\Http\Requests\StudentCreditLimit;

use Illuminate\Foundation\Http\FormRequest;

class StoreStudentCreditLimitRequest extends FormRequest
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
            'min_credit_hours' => 'required|integer',
            'max_credit_hours' => 'required|integer',
            'is_excellent_student' => 'required|integer',
            'approved_by_user_id' => 'nullable|integer|exists:users,user_id',
            'notes' => 'nullable|string|max:255',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
