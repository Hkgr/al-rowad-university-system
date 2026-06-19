<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => 'sometimes|nullable|string|max:80',
            'email' => 'sometimes|nullable|string|max:150',
            'password_hash' => 'sometimes|nullable|string|max:255',
            'account_status_id' => 'sometimes|nullable|integer|exists:account_statuses,account_status_id',
            'student_id' => 'sometimes|nullable|integer|exists:students,student_id',
            'employee_id' => 'sometimes|nullable|integer|exists:employees,employee_id',
            'board_member_id' => 'sometimes|nullable|integer|exists:board_members,board_member_id',
            'last_login_at' => 'sometimes|nullable|date',
            'email_verified_at' => 'sometimes|nullable|date',
            'failed_login_attempts' => 'sometimes|nullable|integer',
            'created_by_user_id' => 'sometimes|nullable|integer|exists:users,user_id',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
