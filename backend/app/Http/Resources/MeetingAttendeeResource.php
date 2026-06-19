<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MeetingAttendee */
class MeetingAttendeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'meeting_attendee_id' => $this->meeting_attendee_id,
            'board_meeting_id' => $this->board_meeting_id,
            'board_member_id' => $this->board_member_id,
            'attendance_status' => $this->attendance_status,
            'notes' => $this->notes,
        ];
    }
}
