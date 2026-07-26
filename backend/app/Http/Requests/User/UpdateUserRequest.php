<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user');

        return [
            'username' => [
                'sometimes',
                'required',
                'string',
                'max:80',
                Rule::unique('users', 'username')->ignore($userId, 'user_id'),
            ],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:150',
                Rule::unique('users', 'email')->ignore($userId, 'user_id'),
            ],
            'password' => ['sometimes', 'nullable', 'confirmed', Password::min(12)],
            'account_status_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:account_statuses,account_status_id',
            ],
            'student_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:students,student_id',
                'prohibits:employee_id,board_member_id',
            ],
            'employee_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:employees,employee_id',
                'prohibits:student_id,board_member_id',
            ],
            'board_member_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:board_members,board_member_id',
                'prohibits:student_id,employee_id',
            ],
        ];
    }
}
