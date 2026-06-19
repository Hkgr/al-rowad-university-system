<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\BoardDecisionAttachment */
class BoardDecisionAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'attachment_id' => $this->attachment_id,
            'board_decision_id' => $this->board_decision_id,
            'file_name' => $this->file_name,
            'file_url' => $this->file_url,
            'uploaded_by_user_id' => $this->uploaded_by_user_id,
            'uploaded_at' => $this->uploaded_at,
        ];
    }
}
