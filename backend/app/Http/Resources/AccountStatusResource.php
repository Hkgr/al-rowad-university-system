<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AccountStatus */
class AccountStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'account_status_id' => $this->account_status_id,
            'status_code' => $this->status_code,
            'status_name' => $this->status_name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
