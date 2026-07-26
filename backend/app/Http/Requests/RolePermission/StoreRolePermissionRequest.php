<?php

namespace App\Http\Requests\RolePermission;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'permission_id' => [
                'required',
                'integer',
                'exists:permissions,permission_id',
                Rule::unique('role_permissions', 'permission_id')
                    ->where(fn ($query) => $query->where('role_id', $this->integer('role_id'))),
            ],
        ];
    }
}
