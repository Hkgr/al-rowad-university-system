<?php

namespace App\Http\Requests\BoardMember;

use Illuminate\Foundation\Http\FormRequest;

class StoreBoardMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'board_id' => 'required|integer|exists:boards,board_id',
            'employee_id' => 'nullable|integer|exists:employees,employee_id',
            'full_name' => 'required|string|max:200',
            'member_title' => 'nullable|string|max:150',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'is_active' => 'required|integer',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
