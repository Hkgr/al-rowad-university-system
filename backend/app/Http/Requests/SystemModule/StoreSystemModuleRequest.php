<?php

namespace App\Http\Requests\SystemModule;

use Illuminate\Foundation\Http\FormRequest;

class StoreSystemModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'module_code' => 'required|string|max:80',
            'module_name' => 'required|string|max:150',
            'description' => 'nullable|string|max:255',
            'is_active' => 'required|integer',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
