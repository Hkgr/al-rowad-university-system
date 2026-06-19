<?php

namespace App\Http\Requests\RolePermission;

use Illuminate\Foundation\Http\FormRequest;

class StoreRolePermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role_id' => 'required|integer|exists:roles,role_id',
            'permission_id' => 'required|integer|exists:permissions,permission_id',
            'granted_at' => 'nullable|date',
        ];
    }
}
