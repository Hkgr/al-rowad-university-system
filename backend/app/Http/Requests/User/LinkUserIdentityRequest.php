<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class LinkUserIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->effectiveRoles()->contains('super_admin') === true;
    }

    public function rules(): array
    {
        return [
            'student_id' => ['sometimes', 'nullable', 'integer', 'exists:students,student_id'],
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,employee_id'],
            'board_member_id' => ['sometimes', 'nullable', 'integer', 'exists:board_members,board_member_id'],
        ];
    }
}
