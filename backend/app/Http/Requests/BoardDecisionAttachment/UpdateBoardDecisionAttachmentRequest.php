<?php

namespace App\Http\Requests\BoardDecisionAttachment;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBoardDecisionAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'board_decision_id' => 'sometimes|nullable|integer|exists:board_decisions,board_decision_id',
            'file_name' => 'sometimes|nullable|string|max:255',
            'file_url' => 'sometimes|nullable|string|max:500',
            'uploaded_by_user_id' => 'sometimes|nullable|integer|exists:users,user_id',
            'uploaded_at' => 'sometimes|nullable|date',
        ];
    }
}
