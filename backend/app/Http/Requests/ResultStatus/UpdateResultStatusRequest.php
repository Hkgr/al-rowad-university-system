<?php

namespace App\Http\Requests\ResultStatus;

use Illuminate\Foundation\Http\FormRequest;

class UpdateResultStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status_code' => 'sometimes|nullable|string|max:50',
            'status_name' => 'sometimes|nullable|string|max:100',
            'is_active' => 'sometimes|nullable|integer',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
