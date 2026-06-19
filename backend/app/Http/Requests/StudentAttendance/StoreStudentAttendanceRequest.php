<?php

namespace App\Http\Requests\StudentAttendance;

use Illuminate\Foundation\Http\FormRequest;

class StoreStudentAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attendance_session_id' => 'required|integer|exists:attendance_sessions,attendance_session_id',
            'student_id' => 'required|integer|exists:students,student_id',
            'attendance_status_id' => 'required|integer|exists:attendance_statuses,attendance_status_id',
            'notes' => 'nullable|string|max:255',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
