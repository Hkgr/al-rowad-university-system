<?php

namespace App\Http\Requests\MeetingAttendee;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMeetingAttendeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'board_meeting_id' => 'sometimes|nullable|integer|exists:board_meetings,board_meeting_id',
            'board_member_id' => 'sometimes|nullable|integer|exists:board_members,board_member_id',
            'attendance_status' => 'sometimes|nullable|string|max:50',
            'notes' => 'sometimes|nullable|string|max:255',
        ];
    }
}
