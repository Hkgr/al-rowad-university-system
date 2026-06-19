<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\EmployeeStatus */
class EmployeeStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'employee_status_id' => $this->employee_status_id,
            'status_code' => $this->status_code,
            'status_name' => $this->status_name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
