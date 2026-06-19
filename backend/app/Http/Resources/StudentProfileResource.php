<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Student */
class StudentProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'student_id' => $this->student_id,
            'student_number' => $this->student_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => trim($this->first_name.' '.$this->last_name),
            'father_name' => $this->father_name,
            'mother_name' => $this->mother_name,
            'date_of_birth' => $this->date_of_birth,
            'gender' => $this->gender,
            'phone_number' => $this->phone_number,
            'email' => $this->email,
            'address' => $this->address,
            'nationality' => $this->nationality,
            'enrollment_date' => $this->enrollment_date,
            'academic_level' => AcademicLevelResource::make($this->whenLoaded('currentAcademicLevel')),
            'student_status' => StudentStatusResource::make($this->whenLoaded('studentStatus')),
            'program' => AcademicProgramResource::make($this->whenLoaded('academicProgram')),
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
        ];
    }
}
