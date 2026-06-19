<?php

namespace App\Http\Requests\BoardMember;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBoardMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'board_id' => 'sometimes|nullable|integer|exists:boards,board_id',
            'employee_id' => 'sometimes|nullable|integer|exists:employees,employee_id',
            'full_name' => 'sometimes|nullable|string|max:200',
            'member_title' => 'sometimes|nullable|string|max:150',
            'start_date' => 'sometimes|nullable|date',
            'end_date' => 'sometimes|nullable|date',
            'is_active' => 'sometimes|nullable|integer',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
