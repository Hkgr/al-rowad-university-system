<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CourseInstructor */
class CourseInstructorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'course_instructor_id' => $this->course_instructor_id,
            'course_id' => $this->course_id,
            'faculty_member_id' => $this->faculty_member_id,
            'is_primary' => $this->is_primary,
            'is_active' => $this->is_active,
            'faculty_member' => $this->relationLoaded('facultyMember') ? new FacultyMemberResource($this->facultyMember) : null,
            'created_at' => $this->created_at,
        ];
    }
}
