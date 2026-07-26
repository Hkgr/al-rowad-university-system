<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:80', 'unique:users,username'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)],
            'account_status_id' => ['required', 'integer', 'exists:account_statuses,account_status_id'],
            'student_id' => [
                'nullable',
                'integer',
                'exists:students,student_id',
                'prohibits:employee_id,board_member_id',
            ],
            'employee_id' => [
                'nullable',
                'integer',
                'exists:employees,employee_id',
                'prohibits:student_id,board_member_id',
            ],
            'board_member_id' => [
                'nullable',
                'integer',
                'exists:board_members,board_member_id',
                'prohibits:student_id,employee_id',
            ],
        ];
    }
}
