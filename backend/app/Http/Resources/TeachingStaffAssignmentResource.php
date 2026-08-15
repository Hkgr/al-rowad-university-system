<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CourseOfferingInstructor */
class TeachingStaffAssignmentResource extends JsonResource
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
            'course_offering_instructor_id' => $this->course_offering_instructor_id,
            'instructor_role' => $this->instructor_role,
            'is_primary' => (bool) $this->is_primary,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'course_offering' => $offering === null ? null : [
                'course_offering_id' => $offering->course_offering_id,
                'status' => $offering->status,
                'course' => $course === null ? null : [
                    'course_id' => $course->course_id,
                    'course_code' => $course->course_code,
                    'course_name' => $course->course_name,
                    'theoretical_hours' => $course->theoretical_hours,
                    'practical_hours' => $course->practical_hours,
                    'credit_hours' => $course->credit_hours,
                ],
                'academic_year' => $academicYear === null ? null : [
                    'academic_year_id' => $academicYear->academic_year_id,
                    'year_name' => $academicYear->year_name,
                ],
                'semester' => $semester === null ? null : [
                    'semester_id' => $semester->semester_id,
                    'semester_name' => $semester->semester_name,
                    'semester_order' => $semester->semester_order,
                ],
                'department' => $department === null ? null : [
                    'department_id' => $department->department_id,
                    'department_name' => $department->department_name,
                ],
                'academic_program' => $program === null ? null : [
                    'academic_program_id' => $program->academic_program_id,
                    'program_name' => $program->program_name,
                ],
            ],
        ];
    }
}
