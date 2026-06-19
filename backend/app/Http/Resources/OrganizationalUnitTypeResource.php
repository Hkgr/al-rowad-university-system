<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\OrganizationalUnitType */
class OrganizationalUnitTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'unit_type_id' => $this->unit_type_id,
            'type_code' => $this->type_code,
            'type_name' => $this->type_name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
