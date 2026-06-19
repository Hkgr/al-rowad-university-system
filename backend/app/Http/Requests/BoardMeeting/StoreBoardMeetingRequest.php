<?php

namespace App\Http\Requests\BoardMeeting;

use Illuminate\Foundation\Http\FormRequest;

class StoreBoardMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'board_id' => 'required|integer|exists:boards,board_id',
            'meeting_title' => 'required|string|max:200',
            'meeting_date' => 'required|date',
            'location' => 'nullable|string|max:200',
            'agenda' => 'nullable|string',
            'minutes' => 'nullable|string',
            'created_by_user_id' => 'nullable|integer|exists:users,user_id',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
