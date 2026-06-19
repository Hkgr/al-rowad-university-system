<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Student */
class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'student_id' => $this->student_id,
            'student_number' => $this->student_number,
            'admission_application_id' => $this->admission_application_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'father_name' => $this->father_name,
            'mother_name' => $this->mother_name,
            'date_of_birth' => $this->date_of_birth,
            'gender' => $this->gender,
            'phone_number' => $this->phone_number,
            'email' => $this->email,
            'address' => $this->address,
            'nationality' => $this->nationality,
            'academic_program_id' => $this->academic_program_id,
            'current_academic_level_id' => $this->current_academic_level_id,
            'enrollment_date' => $this->enrollment_date,
            'student_status_id' => $this->student_status_id,
            'admission_application' => new AdmissionApplicationResource($this->whenLoaded('admissionApplication')),
            'academic_program' => new AcademicProgramResource($this->whenLoaded('academicProgram')),
            'current_academic_level' => new AcademicLevelResource($this->whenLoaded('currentAcademicLevel')),
            'student_status' => new StudentStatusResource($this->whenLoaded('studentStatus')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
