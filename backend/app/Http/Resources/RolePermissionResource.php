<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\RolePermission */
class RolePermissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'role_permission_id' => $this->role_permission_id,
            'role_id' => $this->role_id,
            'permission_id' => $this->permission_id,
            'granted_at' => $this->granted_at,
        ];
    }
}
