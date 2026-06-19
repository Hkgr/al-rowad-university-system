<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\FacultyMember */
class FacultyMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'faculty_member_id' => $this->faculty_member_id,
            'employee_id' => $this->employee_id,
            'academic_rank' => $this->academic_rank,
            'specialization' => $this->specialization,
            'office_location' => $this->office_location,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
