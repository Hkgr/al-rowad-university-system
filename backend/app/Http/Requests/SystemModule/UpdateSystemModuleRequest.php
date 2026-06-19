<?php

namespace App\Http\Requests\SystemModule;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSystemModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'module_code' => 'sometimes|nullable|string|max:80',
            'module_name' => 'sometimes|nullable|string|max:150',
            'description' => 'sometimes|nullable|string|max:255',
            'is_active' => 'sometimes|nullable|integer',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
