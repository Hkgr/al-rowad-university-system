<?php

namespace App\Http\Requests\AttendanceStatus;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAttendanceStatusRequest extends FormRequest
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
            'counts_as_absent' => 'sometimes|nullable|integer',
            'is_active' => 'sometimes|nullable|integer',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
