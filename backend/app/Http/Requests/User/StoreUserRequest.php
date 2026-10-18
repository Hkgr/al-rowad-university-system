<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return false; // Unrouted: writes go through AccountAdministrationService.
    }

    public function rules(): array
    {
        return [
            'username' => 'required|string|max:80',
            'email' => 'required|string|max:150',
            'password_hash' => 'prohibited',
            'account_status_id' => 'required|integer|exists:account_statuses,account_status_id',
            'student_id' => 'prohibited',
            'employee_id' => 'prohibited',
            'board_member_id' => 'prohibited',
            'last_login_at' => 'nullable|date',
            'email_verified_at' => 'nullable|date',
            'failed_login_attempts' => 'required|integer',
            'created_by_user_id' => 'prohibited',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
