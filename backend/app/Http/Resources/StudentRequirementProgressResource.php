<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentRequirementProgressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $progress = is_array($this->resource) ? $this->resource : [];

        return [
            'student_id' => $progress['student_id'] ?? null,
            'academic_program_id' => $progress['academic_program_id'] ?? null,
            'total_required_hours' => $progress['total_required_hours'] ?? 0,
            'earned_curriculum_hours' => $progress['earned_curriculum_hours'] ?? 0,
            'committed_curriculum_hours' => $progress['committed_curriculum_hours'] ?? 0,
            'remaining_required_hours' => $progress['remaining_required_hours'] ?? 0,
            'remaining_commitment_capacity' => $progress['remaining_commitment_capacity'] ?? 0,
            'groups' => $progress['groups'] ?? [],
            'outside_current_curriculum' => $progress['outside_current_curriculum'] ?? [],
        ];
    }
}
