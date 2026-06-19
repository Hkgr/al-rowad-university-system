<?php

namespace App\Http\Requests\StudentAttendance;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attendance_session_id' => 'sometimes|nullable|integer|exists:attendance_sessions,attendance_session_id',
            'student_id' => 'sometimes|nullable|integer|exists:students,student_id',
            'attendance_status_id' => 'sometimes|nullable|integer|exists:attendance_statuses,attendance_status_id',
            'notes' => 'sometimes|nullable|string|max:255',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
