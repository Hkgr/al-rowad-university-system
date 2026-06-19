<?php

namespace App\Http\Requests\Permission;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'module_id' => 'sometimes|nullable|integer|exists:system_modules,module_id',
            'permission_code' => 'sometimes|nullable|string|max:120',
            'permission_name' => 'sometimes|nullable|string|max:150',
            'description' => 'sometimes|nullable|string|max:255',
            'is_active' => 'sometimes|nullable|integer',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
