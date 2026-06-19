<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => 'required|string|max:80',
            'email' => 'required|string|max:150',
            'password_hash' => 'required|string|max:255',
            'account_status_id' => 'required|integer|exists:account_statuses,account_status_id',
            'student_id' => 'nullable|integer|exists:students,student_id',
            'employee_id' => 'nullable|integer|exists:employees,employee_id',
            'board_member_id' => 'nullable|integer|exists:board_members,board_member_id',
            'last_login_at' => 'nullable|date',
            'email_verified_at' => 'nullable|date',
            'failed_login_attempts' => 'required|integer',
            'created_by_user_id' => 'nullable|integer|exists:users,user_id',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
