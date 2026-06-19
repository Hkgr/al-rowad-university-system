<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StudentStatus */
class StudentStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'student_status_id' => $this->student_status_id,
            'status_code' => $this->status_code,
            'status_name' => $this->status_name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'students' => $this->relationLoaded('students') ? StudentResource::collection($this->students) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
