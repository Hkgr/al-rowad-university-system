<?php

namespace App\Http\Requests\PasswordResetToken;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePasswordResetTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => 'sometimes|nullable|integer|exists:users,user_id',
            'token_hash' => 'sometimes|nullable|string|max:255',
            'expires_at' => 'sometimes|nullable|date',
            'used_at' => 'sometimes|nullable|date',
            'created_at' => 'sometimes|nullable|date',
        ];
    }
}
