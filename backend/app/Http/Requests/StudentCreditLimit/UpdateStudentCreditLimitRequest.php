<?php

namespace App\Http\Requests\StudentCreditLimit;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentCreditLimitRequest extends FormRequest
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
            'min_credit_hours' => 'sometimes|nullable|integer',
            'max_credit_hours' => 'sometimes|nullable|integer',
            'is_excellent_student' => 'sometimes|nullable|integer',
            'approved_by_user_id' => 'sometimes|nullable|integer|exists:users,user_id',
            'notes' => 'sometimes|nullable|string|max:255',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
