<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Student */
class StudentAcademicInfoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'student_id' => $this->student_id,
            'student_number' => $this->student_number,
            'full_name' => trim($this->first_name.' '.$this->last_name),
            'program' => AcademicProgramResource::make($this->whenLoaded('academicProgram')),
            'academic_level' => AcademicLevelResource::make($this->whenLoaded('currentAcademicLevel')),
            'student_status' => StudentStatusResource::make($this->whenLoaded('studentStatus')),
            'department' => DepartmentResource::make($this->when(
                $this->relationLoaded('academicProgram') && $this->academicProgram?->relationLoaded('department'),
                $this->academicProgram?->department
            )),
            'college' => CollegeResource::make($this->when(
                $this->relationLoaded('academicProgram')
                    && $this->academicProgram?->relationLoaded('department')
                    && $this->academicProgram->department?->relationLoaded('college'),
                $this->academicProgram?->department?->college
            )),
            'enrollment_date' => $this->enrollment_date,
        ];
    }
}