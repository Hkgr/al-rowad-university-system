<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\UserRole */
class UserRoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user_role_id' => $this->user_role_id,
            'user_id' => $this->user_id,
            'role_id' => $this->role_id,
            'assigned_by_user_id' => $this->assigned_by_user_id,
            'assigned_at' => $this->assigned_at,
            'is_active' => $this->is_active,
        ];
    }
}
