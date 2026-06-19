<?php

namespace App\Http\Requests\Board;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'board_code' => 'sometimes|nullable|string|max:50',
            'board_name' => 'sometimes|nullable|string|max:150',
            'organizational_unit_id' => 'sometimes|nullable|integer|exists:organizational_units,organizational_unit_id',
            'description' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|nullable|integer',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
