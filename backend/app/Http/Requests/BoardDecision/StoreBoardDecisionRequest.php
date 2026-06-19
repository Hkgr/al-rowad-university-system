<?php

namespace App\Http\Requests\BoardDecision;

use Illuminate\Foundation\Http\FormRequest;

class StoreBoardDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'board_meeting_id' => 'required|integer|exists:board_meetings,board_meeting_id',
            'decision_number' => 'nullable|string|max:80',
            'decision_title' => 'required|string|max:200',
            'decision_text' => 'required|string',
            'decision_date' => 'nullable|date',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
