<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class RecordAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'records' => ['required', 'array', 'min:1'],
            'records.*.student_course_registration_id' => ['required', 'integer', 'exists:student_course_registrations,student_course_registration_id'],
            'records.*.attendance_status_id' => ['nullable', 'integer', 'exists:attendance_statuses,attendance_status_id', 'required_without:records.*.status_code'],
            'records.*.status_code' => ['nullable', 'string', 'max:50', 'required_without:records.*.attendance_status_id'],
            'records.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
