<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ProgramCourse */
class ProgramCourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'program_course_id' => $this->program_course_id,
            'academic_program_id' => $this->academic_program_id,
            'course_id' => $this->course_id,
            'academic_level_id' => $this->academic_level_id,
            'recommended_semester_id' => $this->recommended_semester_id,
            'course_type' => $this->course_type,
            'is_active' => $this->is_active,
            'academic_program' => $this->relationLoaded('academicProgram') ? new AcademicProgramResource($this->academicProgram) : null,
            'course' => $this->relationLoaded('course') ? new CourseResource($this->course) : null,
            'academic_level' => $this->relationLoaded('academicLevel') ? new AcademicLevelResource($this->academicLevel) : null,
            'recommended_semester' => $this->relationLoaded('recommendedSemester') ? new SemesterResource($this->recommendedSemester) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
