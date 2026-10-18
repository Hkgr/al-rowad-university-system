<?php

namespace App\Http\Requests\DisciplinaryCase;

use Illuminate\Foundation\Http\FormRequest;

class StoreDisciplinaryCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => 'required|integer|exists:students,student_id',
            'violation_type_id' => 'required|integer|exists:disciplinary_violation_types,violation_type_id',
            'trigger_course_offering_id' => 'nullable|integer|exists:course_offerings,course_offering_id',
            'violation_description' => 'required|string',
            'violation_date' => 'required|date',
            'decision_number' => 'nullable|string|max:80',
            'decision_date' => 'required|date',
            'penalty_type_id' => 'required|integer|exists:disciplinary_penalty_types,penalty_type_id',
            'penalty_start_date' => 'nullable|date',
            'penalty_end_date' => 'nullable|date|after_or_equal:penalty_start_date',
            'is_in_absentia' => 'boolean',
            'decided_by_authority' => 'required|string|in:instructor,dean_or_institute_director,university_president,disciplinary_council',
        ];
    }
}
