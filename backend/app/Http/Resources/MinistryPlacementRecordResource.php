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
            'matched_academic_program_id' => $this->matched_academic_program_id === null ? null : (int) $this->matched_academic_program_id,
            'matched_academic_program' => $this->whenLoaded('matchedAcademicProgram', function (): ?array {
                $program = $this->matchedAcademicProgram;
                if ($program === null) {
                    return null;
                }
                $department = $program->relationLoaded('department') ? $program->department : null;
                $college = $department?->relationLoaded('college') ? $department->college : null;

                return [
                    'academic_program_id' => (int) $program->academic_program_id,
                    'program_code' => $program->program_code,
                    'program_name' => $program->program_name,
                    'is_active' => (bool) $program->is_active,
                    'department' => $department === null ? null : [
                        'department_id' => (int) $department->department_id,
                        'department_code' => $department->department_code,
                        'department_name' => $department->department_name,
                        'is_active' => (bool) $department->is_active,
                    ],
                    'college' => $college === null ? null : [
                        'college_id' => (int) $college->college_id,
                        'college_code' => $college->college_code,
                        'college_name' => $college->college_name,
                        'is_active' => (bool) $college->is_active,
                    ],
                ];
            }),
            'program_match_state' => $this->programMatchState(),
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
