<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\BoardMeeting */
class BoardMeetingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'board_meeting_id' => $this->board_meeting_id,
            'board_id' => $this->board_id,
            'meeting_title' => $this->meeting_title,
            'meeting_date' => $this->meeting_date,
            'location' => $this->location,
            'agenda' => $this->agenda,
            'minutes' => $this->minutes,
            'created_by_user_id' => $this->created_by_user_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
