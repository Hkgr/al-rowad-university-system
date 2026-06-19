<?php

namespace App\Http\Requests\LibraryCategory;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLibraryCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_name' => 'sometimes|nullable|string|max:150',
            'description' => 'sometimes|nullable|string|max:255',
            'is_active' => 'sometimes|nullable|integer',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
