<?php

namespace App\Http\Requests\UserRole;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|integer|exists:users,user_id',
            'role_id' => 'required|integer|exists:roles,role_id',
        ];
    }
}
