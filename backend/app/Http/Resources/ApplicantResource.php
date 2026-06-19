<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Applicant */
class ApplicantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'applicant_id' => $this->applicant_id,
            'applicant_number' => $this->applicant_number,
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
            'admission_applications' => $this->relationLoaded('admissionApplications') ? AdmissionApplicationResource::collection($this->admissionApplications) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
