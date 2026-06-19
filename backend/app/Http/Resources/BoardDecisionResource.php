<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\BoardDecision */
class BoardDecisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'board_decision_id' => $this->board_decision_id,
            'board_meeting_id' => $this->board_meeting_id,
            'decision_number' => $this->decision_number,
            'decision_title' => $this->decision_title,
            'decision_text' => $this->decision_text,
            'decision_date' => $this->decision_date,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
