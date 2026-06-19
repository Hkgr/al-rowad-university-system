<?php

namespace App\Http\Requests\BoardDecision;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBoardDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'board_meeting_id' => 'sometimes|nullable|integer|exists:board_meetings,board_meeting_id',
            'decision_number' => 'sometimes|nullable|string|max:80',
            'decision_title' => 'sometimes|nullable|string|max:200',
            'decision_text' => 'sometimes|nullable|string',
            'decision_date' => 'sometimes|nullable|date',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
