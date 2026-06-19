<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Position */
class PositionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'position_id' => $this->position_id,
            'position_code' => $this->position_code,
            'position_title' => $this->position_title,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
