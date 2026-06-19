<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\BoardMember */
class BoardMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'board_member_id' => $this->board_member_id,
            'board_id' => $this->board_id,
            'employee_id' => $this->employee_id,
            'full_name' => $this->full_name,
            'member_title' => $this->member_title,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
