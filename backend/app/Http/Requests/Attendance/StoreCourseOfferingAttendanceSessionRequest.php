<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class StoreCourseOfferingAttendanceSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session_date' => ['required', 'date'],
            'session_type' => ['nullable', 'string', 'max:50', 'in:theoretical,practical,lecture'],
            'topic' => ['nullable', 'string', 'max:255'],
            'start_time' => ['nullable', 'date_format:H:i:s'],
            'end_time' => ['nullable', 'date_format:H:i:s'],
            'faculty_member_id' => ['nullable', 'integer', 'exists:faculty_members,faculty_member_id'],
        ];
    }
}
