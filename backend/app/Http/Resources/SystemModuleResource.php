<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SystemModule */
class SystemModuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'module_id' => $this->module_id,
            'module_code' => $this->module_code,
            'module_name' => $this->module_name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
