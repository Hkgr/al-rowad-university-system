<?php

namespace App\Http\Resources;

use App\Models\FacultyMember;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AttendanceSession */
class DeanCourseOfferingSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $facultyMember = $this->facultyMember;

        return [
            'attendance_session_id' => $this->attendance_session_id,
            'course_offering_id' => $this->course_offering_id,
            'session_date' => optional($this->session_date)?->toDateString(),
            'session_type' => $this->session_type,
            'start_time' => $this->formatClock($this->start_time),
            'end_time' => $this->formatClock($this->end_time),
            'notes' => $this->notes,
            'recorded_attendance_count' => (int) ($this->recorded_count ?? 0),
            'faculty_member_id' => $this->faculty_member_id,
            'teacher' => $this->historicalTeacher($facultyMember),
        ];
    }

    private function historicalTeacher(?FacultyMember $facultyMember): ?array
    {
        if ($facultyMember === null) {
            return null;
        }

        $employee = $facultyMember->employee;
        $fullName = trim(($employee?->first_name ?? '').' '.($employee?->last_name ?? ''));

        return [
            'faculty_member_id' => $facultyMember->faculty_member_id,
            'full_name' => $fullName !== '' ? $fullName : null,
            'academic_rank' => $facultyMember->academic_rank,
        ];
    }

    private function formatClock(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('H:i');
        } catch (\Throwable) {
            return is_string($value) ? $value : null;
        }
    }
}
