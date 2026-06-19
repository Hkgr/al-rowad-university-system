<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LoginAuditLog */
class LoginAuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'login_audit_id' => $this->login_audit_id,
            'user_id' => $this->user_id,
            'username_attempted' => $this->username_attempted,
            'login_status' => $this->login_status,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'attempted_at' => $this->attempted_at,
        ];
    }
}
