<?php

namespace App\Http\Requests\LoginAuditLog;

use Illuminate\Foundation\Http\FormRequest;

class StoreLoginAuditLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => 'nullable|integer|exists:users,user_id',
            'username_attempted' => 'nullable|string|max:100',
            'login_status' => 'required|string|max:50',
            'ip_address' => 'nullable|string|max:45',
            'user_agent' => 'nullable|string|max:255',
            'attempted_at' => 'nullable|date',
        ];
    }
}
