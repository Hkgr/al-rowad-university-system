<?php

namespace App\Http\Requests\LibraryAuthor;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLibraryAuthorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'author_name' => 'sometimes|nullable|string|max:200',
            'biography' => 'sometimes|nullable|string',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
