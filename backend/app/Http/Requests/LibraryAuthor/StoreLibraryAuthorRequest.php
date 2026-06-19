<?php

namespace App\Http\Requests\LibraryAuthor;

use Illuminate\Foundation\Http\FormRequest;

class StoreLibraryAuthorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'author_name' => 'required|string|max:200',
            'biography' => 'nullable|string',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
