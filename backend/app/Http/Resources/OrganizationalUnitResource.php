<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\OrganizationalUnit */
class OrganizationalUnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'organizational_unit_id' => $this->organizational_unit_id,
            'unit_code' => $this->unit_code,
            'unit_name' => $this->unit_name,
            'unit_type_id' => $this->unit_type_id,
            'parent_unit_id' => $this->parent_unit_id,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
