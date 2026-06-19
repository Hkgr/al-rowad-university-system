<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\EmployeePosition */
class EmployeePositionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'employee_position_id' => $this->employee_position_id,
            'employee_id' => $this->employee_id,
            'position_id' => $this->position_id,
            'organizational_unit_id' => $this->organizational_unit_id,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'is_primary' => $this->is_primary,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
