<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AttendanceSession */
class TeachingStaffSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $offering = $this->courseOffering;
        $course = $offering?->course;
        $academicYear = $offering?->academicYear;
        $semester = $offering?->semester;
        $department = $offering?->department;
        $program = $offering?->academicProgram;

        return [
            'attendance_session_id' => $this->attendance_session_id,
            'course_offering_id' => $this->course_offering_id,
            'session_type' => $this->session_type,
            'session_date' => optional($this->session_date)?->toDateString(),
            'start_time' => $this->formatClock($this->start_time),
            'end_time' => $this->formatClock($this->end_time),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'recorded_count' => (int) ($this->recorded_count ?? 0),
            'course' => $course === null ? null : [
                'course_code' => $course->course_code,
                'course_name' => $course->course_name,
            ],
            'academic_year' => $academicYear === null ? null : [
                'year_name' => $academicYear->year_name,
            ],
            'semester' => $semester === null ? null : [
                'semester_name' => $semester->semester_name,
            ],
            'department' => $department === null ? null : [
                'department_name' => $department->department_name,
            ],
            'academic_program' => $program === null ? null : [
                'program_name' => $program->program_name,
            ],
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
