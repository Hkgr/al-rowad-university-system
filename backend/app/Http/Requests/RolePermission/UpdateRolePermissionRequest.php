<?php

namespace App\Http\Requests\RolePermission;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRolePermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role_id' => 'sometimes|nullable|integer|exists:roles,role_id',
            'permission_id' => 'sometimes|nullable|integer|exists:permissions,permission_id',
            'granted_at' => 'sometimes|nullable|date',
        ];
    }
}
