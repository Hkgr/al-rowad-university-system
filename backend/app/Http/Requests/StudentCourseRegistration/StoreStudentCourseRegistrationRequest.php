<?php

namespace App\Http\Requests\StudentCourseRegistration;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentCourseRegistrationRequest extends FormRequest
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
            'registration_date' => 'nullable|date',
            'registered_by_user_id' => 'required|integer|exists:users,user_id',
            'advisor_user_id' => 'nullable|integer|exists:users,user_id',
            'registration_status_id' => 'nullable|integer|exists:registration_statuses,registration_status_id',
            'result_status_id' => 'nullable|integer|exists:result_statuses,result_status_id',
            'notes' => 'nullable|string|max:255',
        ];
    }
}
