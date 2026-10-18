<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GraduationEligibilityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $eligibility = is_array($this->resource) ? $this->resource : [];

        return [
            'student_id' => $eligibility['student_id'] ?? null,
            'academic_program_id' => $eligibility['academic_program_id'] ?? null,
            'eligible' => (bool) ($eligibility['eligible'] ?? false),
            'total_required_hours' => $eligibility['total_required_hours'] ?? 0,
            'actual_earned_curriculum_hours' => $eligibility['actual_earned_curriculum_hours'] ?? 0,
            'graduation_counted_hours' => $eligibility['graduation_counted_hours'] ?? 0,
            'remaining_graduation_hours' => $eligibility['remaining_graduation_hours'] ?? 0,
            'mandatory_completed' => (bool) ($eligibility['mandatory_completed'] ?? false),
            'elective_completed' => (bool) ($eligibility['elective_completed'] ?? false),
            'all_groups_completed' => (bool) ($eligibility['all_groups_completed'] ?? false),
            'groups' => $eligibility['groups'] ?? [],
            'blockers' => $eligibility['blockers'] ?? [],
            'outside_current_curriculum' => $eligibility['outside_current_curriculum'] ?? [],
        ];
    }
}
