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
        return [
            'theoretical_faculty_member_id' => ['nullable', 'integer', 'exists:faculty_members,faculty_member_id'],
            'practical_faculty_member_id' => ['nullable', 'integer', 'exists:faculty_members,faculty_member_id'],
            'is_primary' => ['prohibited'],
        ];
    }
}
