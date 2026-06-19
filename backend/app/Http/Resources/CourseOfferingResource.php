<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CourseOffering */
class CourseOfferingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'course_offering_id' => $this->course_offering_id,
            'course_id' => $this->course_id,
            'academic_year_id' => $this->academic_year_id,
            'semester_id' => $this->semester_id,
            'department_id' => $this->department_id,
            'academic_program_id' => $this->academic_program_id,
            'faculty_member_id' => $this->faculty_member_id,
            'capacity' => $this->capacity,
            'available_seats' => $this->available_seats,
            'status' => $this->status,
            'course' => CourseResource::make($this->whenLoaded('course')),
            'academic_year' => AcademicYearResource::make($this->whenLoaded('academicYear')),
            'semester' => SemesterResource::make($this->whenLoaded('semester')),
            'department' => DepartmentResource::make($this->whenLoaded('department')),
            'academic_program' => AcademicProgramResource::make($this->whenLoaded('academicProgram')),
            'faculty_member' => FacultyMemberResource::make($this->whenLoaded('facultyMember')),
            'registered_students_count' => $this->student_course_registrations_count ?? null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
