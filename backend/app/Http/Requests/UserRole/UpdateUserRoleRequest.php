<?php

namespace App\Http\Requests\UserRole;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => 'sometimes|nullable|integer|exists:users,user_id',
            'role_id' => 'sometimes|nullable|integer|exists:roles,role_id',
            'assigned_by_user_id' => 'sometimes|nullable|integer|exists:users,user_id',
            'assigned_at' => 'sometimes|nullable|date',
            'is_active' => 'sometimes|nullable|integer',
        ];
    }
}
