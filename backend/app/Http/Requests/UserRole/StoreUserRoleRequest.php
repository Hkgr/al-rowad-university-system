<?php

namespace App\Http\Requests\UserRole;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return false; // Unrouted: writes go through AccountAdministrationService.
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|integer|exists:users,user_id',
            'role_id' => 'required|integer|exists:roles,role_id',
            'assigned_by_user_id' => 'prohibited',
            'assigned_at' => 'prohibited',
            'is_active' => 'required|integer',
        ];
    }
}
