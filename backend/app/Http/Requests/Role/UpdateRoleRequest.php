<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role_code' => 'sometimes|nullable|string|max:80',
            'role_name' => 'sometimes|nullable|string|max:150',
            'description' => 'sometimes|nullable|string|max:255',
            'is_system_role' => 'sometimes|nullable|integer',
            'is_active' => 'sometimes|nullable|integer',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
