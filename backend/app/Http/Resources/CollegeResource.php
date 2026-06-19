<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\College */
class CollegeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'college_id' => $this->college_id,
            'organizational_unit_id' => $this->organizational_unit_id,
            'college_code' => $this->college_code,
            'college_name' => $this->college_name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'departments' => $this->relationLoaded('departments') ? DepartmentResource::collection($this->departments) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
