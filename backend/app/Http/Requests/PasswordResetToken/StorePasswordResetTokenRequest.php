<?php

namespace App\Http\Requests\PasswordResetToken;

use Illuminate\Foundation\Http\FormRequest;

class StorePasswordResetTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|integer|exists:users,user_id',
            'token_hash' => 'required|string|max:255',
            'expires_at' => 'required|date',
            'used_at' => 'nullable|date',
            'created_at' => 'nullable|date',
        ];
    }
}
