<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\EmployeeUnitAssignment */
class EmployeeUnitAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'assignment_id' => $this->assignment_id,
            'employee_id' => $this->employee_id,
            'organizational_unit_id' => $this->organizational_unit_id,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'assignment_notes' => $this->assignment_notes,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
