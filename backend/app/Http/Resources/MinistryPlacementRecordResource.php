<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MinistryPlacementRecord */
class MinistryPlacementRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'placement_record_id' => (int) $this->placement_record_id,
            'batch_id' => (int) $this->batch_id,
            'row_number' => $this->row_number,
            'national_civil_id' => $this->national_civil_id,
            'subscription_number' => $this->subscription_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'father_name' => $this->father_name,
            'mother_name' => $this->mother_name,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'gender' => $this->gender,
            'nationality' => $this->nationality,
            'phone_number' => $this->phone_number,
            'email' => $this->email,
            'certificate_type' => $this->certificate_type,
            'certificate_source_country' => $this->certificate_source_country,
            'certificate_grant_year' => $this->certificate_grant_year,
            'directorate' => $this->directorate,
            'total_score' => $this->total_score,
            'max_total_score' => $this->max_total_score,
            'accepted_preference_text' => $this->accepted_preference_text,
            'track' => $this->track,
            'placement_round_name' => $this->placement_round_name,
            'registration_type' => $this->registration_type,
            'is_faculty_member_child' => $this->is_faculty_member_child,
            'has_academic_sequence' => $this->has_academic_sequence,
            'processing_status' => $this->processing_status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
