<?php

namespace App\Http\Requests\RegistrationStatus;

use Illuminate\Foundation\Http\FormRequest;

class StoreRegistrationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status_code' => 'required|string|max:50',
            'status_name' => 'required|string|max:100',
            'is_active' => 'required|integer',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
