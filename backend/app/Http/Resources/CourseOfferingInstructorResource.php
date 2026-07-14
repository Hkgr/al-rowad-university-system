<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CourseOfferingInstructor */
class CourseOfferingInstructorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'course_offering_instructor_id' => $this->course_offering_instructor_id,
            'course_offering_id' => $this->course_offering_id,
            'faculty_member_id' => $this->faculty_member_id,
            'instructor_role' => $this->instructor_role,
            'is_primary' => $this->is_primary,
            'is_active' => $this->is_active,
            'faculty_member' => $this->relationLoaded('facultyMember')
                ? new FacultyMemberResource($this->facultyMember)
                : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
