<?php

namespace App\Http\Requests\UserActivityLog;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserActivityLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => 'sometimes|nullable|integer|exists:users,user_id',
            'module_code' => 'sometimes|nullable|string|max:80',
            'action_code' => 'sometimes|nullable|string|max:120',
            'description' => 'sometimes|nullable|string',
            'ip_address' => 'sometimes|nullable|string|max:45',
            'created_at' => 'sometimes|nullable|date',
        ];
    }
}
