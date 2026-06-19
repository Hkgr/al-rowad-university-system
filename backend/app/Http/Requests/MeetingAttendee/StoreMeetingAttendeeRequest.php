<?php

namespace App\Http\Requests\MeetingAttendee;

use Illuminate\Foundation\Http\FormRequest;

class StoreMeetingAttendeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'board_meeting_id' => 'required|integer|exists:board_meetings,board_meeting_id',
            'board_member_id' => 'required|integer|exists:board_members,board_member_id',
            'attendance_status' => 'required|string|max:50',
            'notes' => 'nullable|string|max:255',
        ];
    }
}
