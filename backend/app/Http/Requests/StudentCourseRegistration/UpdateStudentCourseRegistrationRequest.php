<?php

namespace App\Http\Requests\StudentCourseRegistration;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentCourseRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => 'sometimes|nullable|integer|exists:students,student_id',
            'course_offering_id' => 'sometimes|nullable|integer|exists:course_offerings,course_offering_id',
            'registration_date' => 'sometimes|nullable|date',
            'registered_by_user_id' => 'sometimes|nullable|integer|exists:users,user_id',
            'advisor_user_id' => 'sometimes|nullable|integer|exists:users,user_id',
            'registration_status_id' => 'sometimes|nullable|integer|exists:registration_statuses,registration_status_id',
            'result_status_id' => 'sometimes|nullable|integer|exists:result_statuses,result_status_id',
            'notes' => 'sometimes|nullable|string|max:255',
        ];
    }
}
