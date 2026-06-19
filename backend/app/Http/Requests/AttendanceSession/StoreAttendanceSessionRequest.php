<?php

namespace App\Http\Requests\AttendanceSession;

use Illuminate\Foundation\Http\FormRequest;

class StoreAttendanceSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_offering_id' => 'required|integer|exists:course_offerings,course_offering_id',
            'session_type' => 'required|string|max:50',
            'session_date' => 'required|date',
            'start_time' => 'nullable|date_format:H:i:s',
            'end_time' => 'nullable|date_format:H:i:s',
            'faculty_member_id' => 'nullable|integer|exists:faculty_members,faculty_member_id',
            'created_by_user_id' => 'required|integer|exists:users,user_id',
            'notes' => 'nullable|string|max:255',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
