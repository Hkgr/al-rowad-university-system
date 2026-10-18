<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SupplementaryExamPeriod */
class SupplementaryExamPeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $year = $this->academicYear;
        $semester = $this->semester;

        return [
            'supplementary_exam_period_id' => $this->supplementary_exam_period_id,
            'academic_year' => $year === null ? null : [
                'academic_year_id' => $year->academic_year_id,
                'id' => $year->academic_year_id,
                'name' => $year->year_name,
                'year_name' => $year->year_name,
            ],
            'semester' => $semester === null ? null : [
                'semester_id' => $semester->semester_id,
                'id' => $semester->semester_id,
                'name' => $semester->semester_name,
                'semester_name' => $semester->semester_name,
            ],
            'period_name' => $this->period_name,
            'status' => $this->status,
            'start_date' => $this->start_date?->toDateString() ?? $this->start_date,
            'end_date' => $this->end_date?->toDateString() ?? $this->end_date,
            'opened_at' => $this->opened_at,
            'decision_note' => $this->decision_note,
            'opened_by' => $this->relationLoaded('openedBy') ? $this->safeUser($this->openedBy) : null,
        ];
    }

    private function safeUser(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'user_id' => $user->user_id,
            'username' => $user->username,
        ];
    }
}
