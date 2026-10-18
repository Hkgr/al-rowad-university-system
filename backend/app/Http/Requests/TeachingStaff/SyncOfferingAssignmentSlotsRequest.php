<?php

namespace App\Http\Requests\TeachingStaff;

use Illuminate\Foundation\Http\FormRequest;

class SyncOfferingAssignmentSlotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // This endpoint is full-state replacement for both teaching component slots;
        // both keys are intentionally required to prevent accidental unassignment from
        // partial payloads.
        return [
            'theoretical_faculty_member_id' => [
                'present',
                'nullable',
                'integer',
                'exists:faculty_members,faculty_member_id',
            ],
            'practical_faculty_member_id' => [
                'present',
                'nullable',
                'integer',
                'exists:faculty_members,faculty_member_id',
            ],
            'is_primary' => ['prohibited'],
        ];
    }
}
