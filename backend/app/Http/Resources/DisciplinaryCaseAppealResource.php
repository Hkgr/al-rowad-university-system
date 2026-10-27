<?php

namespace App\Http\Resources;

use App\Models\DisciplinaryCaseAppeal;
use App\Support\StudentDisciplineAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DisciplinaryCaseAppeal */
class DisciplinaryCaseAppealResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'appeal_id' => $this->appeal_id,
            'case_id' => $this->case_id,
            'submitted_at' => $this->submitted_at,
            'appeal_reason' => $this->appeal_reason,
            'appeal_status_id' => $this->appeal_status_id,
            'reviewed_by_user_id' => $this->reviewed_by_user_id,
            'decision_date' => $this->decision_date,
            'decision_notes' => $this->decision_notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'appeal_status' => $this->whenLoaded('appealStatus'),
            'disciplinary_case' => $this->whenLoaded('disciplinaryCase', fn () => $this->disciplinaryCase?->attributesToArray()),
            'can_record_decision' => StudentDisciplineAccess::presentationWrite($request)
                && $this->relationLoaded('appealStatus') && $this->appealStatus?->status_code === 'submitted',
        ];
    }
}
