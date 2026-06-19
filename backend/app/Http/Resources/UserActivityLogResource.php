<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\UserActivityLog */
class UserActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'activity_log_id' => $this->activity_log_id,
            'user_id' => $this->user_id,
            'module_code' => $this->module_code,
            'action_code' => $this->action_code,
            'description' => $this->description,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at,
        ];
    }
}
