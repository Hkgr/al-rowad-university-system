<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\FacultyMember */
class TeachingStaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $employee = $this->employee;

        return [
            'faculty_member_id' => $this->faculty_member_id,
            'academic_rank' => $this->academic_rank,
            'specialization' => $this->specialization,
            'office_location' => $this->office_location,
            'is_active' => $this->is_active,
            'employee' => $employee === null ? null : [
                'employee_id' => $employee->employee_id,
                'employee_number' => $employee->employee_number,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'phone_number' => $employee->phone_number,
                'email' => $employee->email,
                'employee_status' => $employee->relationLoaded('employeeStatus') && $employee->employeeStatus !== null
                    ? [
                        'status_code' => $employee->employeeStatus->status_code,
                        'status_name' => $employee->employeeStatus->status_name,
                    ]
                    : null,
            ],
            'home_unit' => $employee?->relationLoaded('organizationalUnit') && $employee->organizationalUnit !== null
                ? [
                    'unit_code' => $employee->organizationalUnit->unit_code,
                    'unit_name' => $employee->organizationalUnit->unit_name,
                ]
                : null,
            'colleges' => $this->when(
                $this->relationLoaded('colleges'),
                fn () => $this->colleges->map(fn ($college) => [
                    'college_id' => $college->college_id,
                    'college_code' => $college->college_code,
                    'college_name' => $college->college_name,
                ])->values()
            ),
            'active_assignment_count' => (int) ($this->active_assignment_count ?? 0),
            'theoretical_assignment_count' => (int) ($this->theoretical_assignment_count ?? 0),
            'practical_assignment_count' => (int) ($this->practical_assignment_count ?? 0),
            'active_course_count' => (int) ($this->active_course_count ?? 0),
        ];
    }
}
