<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AttendanceSession */
class AttendanceSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'attendance_session_id' => $this->attendance_session_id,
            'course_offering_id' => $this->course_offering_id,
            'session_type' => $this->session_type,
            'session_date' => $this->session_date,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'faculty_member_id' => $this->faculty_member_id,
            'created_by_user_id' => $this->created_by_user_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
