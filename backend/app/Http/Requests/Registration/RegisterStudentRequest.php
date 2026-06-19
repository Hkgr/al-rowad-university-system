<?php

namespace App\Http\Requests\Registration;

use Illuminate\Foundation\Http\FormRequest;

class RegisterStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => 'required|integer|exists:students,student_id',
            'course_offering_id' => 'required|integer|exists:course_offerings,course_offering_id',
            'registered_by_user_id' => 'nullable|integer|exists:users,user_id',
            'advisor_user_id' => 'nullable|integer|exists:users,user_id',
            'registration_date' => 'nullable|date',
        ];
    }
}
