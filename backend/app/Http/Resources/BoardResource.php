<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Board */
class BoardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'board_id' => $this->board_id,
            'board_code' => $this->board_code,
            'board_name' => $this->board_name,
            'organizational_unit_id' => $this->organizational_unit_id,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
