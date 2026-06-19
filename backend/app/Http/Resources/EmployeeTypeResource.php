<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\EmployeeType */
class EmployeeTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'employee_type_id' => $this->employee_type_id,
            'type_code' => $this->type_code,
            'type_name' => $this->type_name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
