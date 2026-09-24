<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return false; // Unrouted: writes go through AccountAdministrationService.
    }

    public function rules(): array
    {
        return [
            'username' => 'sometimes|nullable|string|max:80',
            'email' => 'sometimes|nullable|string|max:150',
            'password_hash' => 'prohibited',
            'account_status_id' => 'sometimes|nullable|integer|exists:account_statuses,account_status_id',
            'student_id' => 'prohibited',
            'employee_id' => 'prohibited',
            'board_member_id' => 'prohibited',
            'last_login_at' => 'sometimes|nullable|date',
            'email_verified_at' => 'sometimes|nullable|date',
            'failed_login_attempts' => 'sometimes|nullable|integer',
            'created_by_user_id' => 'prohibited',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
