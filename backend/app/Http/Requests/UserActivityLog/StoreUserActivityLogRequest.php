<?php

namespace App\Http\Requests\UserActivityLog;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserActivityLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|integer|exists:users,user_id',
            'module_code' => 'nullable|string|max:80',
            'action_code' => 'nullable|string|max:120',
            'description' => 'nullable|string',
            'ip_address' => 'nullable|string|max:45',
            'created_at' => 'nullable|date',
        ];
    }
}
