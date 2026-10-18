<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DisciplinaryCaseAppeal */
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
            'disciplinary_case' => $this->whenLoaded('disciplinaryCase'),
            'reviewed_by' => $this->whenLoaded('reviewedBy'),
        ];
    }
}
