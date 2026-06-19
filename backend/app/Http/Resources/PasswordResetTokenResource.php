<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\PasswordResetToken */
class PasswordResetTokenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'token_id' => $this->token_id,
            'user_id' => $this->user_id,
            'token_hash' => $this->token_hash,
            'expires_at' => $this->expires_at,
            'used_at' => $this->used_at,
            'created_at' => $this->created_at,
        ];
    }
}
