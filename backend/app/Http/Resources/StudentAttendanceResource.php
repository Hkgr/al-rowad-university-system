<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StudentAttendance */
class StudentAttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'student_attendance_id' => $this->student_attendance_id,
            'attendance_session_id' => $this->attendance_session_id,
            'student_id' => $this->student_id,
            'attendance_status_id' => $this->attendance_status_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
