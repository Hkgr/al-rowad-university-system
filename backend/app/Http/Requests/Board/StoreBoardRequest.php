<?php

namespace App\Http\Requests\Board;

use Illuminate\Foundation\Http\FormRequest;

class StoreBoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'board_code' => 'required|string|max:50',
            'board_name' => 'required|string|max:150',
            'organizational_unit_id' => 'nullable|integer|exists:organizational_units,organizational_unit_id',
            'description' => 'nullable|string',
            'is_active' => 'required|integer',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
