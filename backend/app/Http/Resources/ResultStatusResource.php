<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ResultStatus */
class ResultStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'result_status_id' => $this->result_status_id,
            'status_code' => $this->status_code,
            'status_name' => $this->status_name,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
