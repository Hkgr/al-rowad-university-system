<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CourseOffering */
class AvailableCourseOfferingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'course_offering_id' => $this->course_offering_id,
            'status' => $this->status,
            'capacity' => $this->capacity,
            'available_seats' => $this->available_seats,
            'course_code' => $this->whenLoaded('course', fn () => $this->course?->course_code),
            'course_name' => $this->whenLoaded('course', fn () => $this->course?->course_name),
            'credit_hours' => $this->whenLoaded('course', fn () => $this->course?->credit_hours),
            'course' => CourseResource::make($this->whenLoaded('course')),
            'academic_year' => AcademicYearResource::make($this->whenLoaded('academicYear')),
            'semester' => SemesterResource::make($this->whenLoaded('semester')),
            'department' => DepartmentResource::make($this->whenLoaded('department')),
            'program' => AcademicProgramResource::make($this->whenLoaded('academicProgram')),
            'faculty_member' => FacultyMemberResource::make($this->whenLoaded('facultyMember')),
            'eligibility_status' => $this->eligibility_status,
            'reasons' => $this->eligibility_reasons ?? [],
        ];
    }
}
