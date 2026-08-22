<?php

namespace App\Http\Requests\GradeAppeal;

use Illuminate\Foundation\Http\FormRequest;

class StoreGradeAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->student_id !== null;
    }

    public function rules(): array
    {
        return [
            'student_course_registration_id' => 'required|integer|exists:student_course_registrations,student_course_registration_id',
            'appeal_reason' => 'required|string',
        ];
    }
}
