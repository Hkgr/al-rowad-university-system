<?php

namespace App\Http\Requests\BoardDecisionAttachment;

use Illuminate\Foundation\Http\FormRequest;

class StoreBoardDecisionAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'board_decision_id' => 'required|integer|exists:board_decisions,board_decision_id',
            'file_name' => 'required|string|max:255',
            'file_url' => 'required|string|max:500',
            'uploaded_by_user_id' => 'nullable|integer|exists:users,user_id',
            'uploaded_at' => 'nullable|date',
        ];
    }
}
