<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AdmissionApplication */
class AdmissionApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'admission_application_id' => $this->admission_application_id,
            'applicant_id' => $this->applicant_id,
            'academic_program_id' => $this->academic_program_id,
            'academic_year_id' => $this->academic_year_id,
            'application_date' => $this->application_date,
            'decision_status' => $this->decision_status,
            'decision_date' => $this->decision_date,
            'decided_by_user_id' => $this->decided_by_user_id,
            'notes' => $this->notes,
            'applicant' => $this->relationLoaded('applicant') ? new ApplicantResource($this->applicant) : null,
            'academic_program' => $this->relationLoaded('academicProgram') ? new AcademicProgramResource($this->academicProgram) : null,
            'academic_year' => $this->relationLoaded('academicYear') ? new AcademicYearResource($this->academicYear) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
