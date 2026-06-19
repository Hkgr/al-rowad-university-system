<?php

namespace App\Http\Requests\LoginAuditLog;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLoginAuditLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => 'sometimes|nullable|integer|exists:users,user_id',
            'username_attempted' => 'sometimes|nullable|string|max:100',
            'login_status' => 'sometimes|nullable|string|max:50',
            'ip_address' => 'sometimes|nullable|string|max:45',
            'user_agent' => 'sometimes|nullable|string|max:255',
            'attempted_at' => 'sometimes|nullable|date',
        ];
    }
}
