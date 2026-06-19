<?php

namespace App\Http\Requests\BoardMeeting;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBoardMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'board_id' => 'sometimes|nullable|integer|exists:boards,board_id',
            'meeting_title' => 'sometimes|nullable|string|max:200',
            'meeting_date' => 'sometimes|nullable|date',
            'location' => 'sometimes|nullable|string|max:200',
            'agenda' => 'sometimes|nullable|string',
            'minutes' => 'sometimes|nullable|string',
            'created_by_user_id' => 'sometimes|nullable|integer|exists:users,user_id',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
