<?php

namespace App\Http\Requests\AttendanceSession;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAttendanceSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_offering_id' => 'sometimes|nullable|integer|exists:course_offerings,course_offering_id',
            'session_type' => 'sometimes|nullable|string|max:50',
            'session_date' => 'sometimes|nullable|date',
            'start_time' => 'sometimes|nullable|date_format:H:i:s',
            'end_time' => 'sometimes|nullable|date_format:H:i:s',
            'faculty_member_id' => 'sometimes|nullable|integer|exists:faculty_members,faculty_member_id',
            'created_by_user_id' => 'sometimes|nullable|integer|exists:users,user_id',
            'notes' => 'sometimes|nullable|string|max:255',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
