<?php

namespace App\Http\Requests\LibraryBook;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLibraryBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'isbn' => 'sometimes|nullable|string|max:50',
            'title' => 'sometimes|nullable|string|max:250',
            'category_id' => 'sometimes|nullable|integer|exists:library_categories,library_category_id',
            'publisher' => 'sometimes|nullable|string|max:200',
            'publication_year' => 'sometimes|nullable|integer',
            'language' => 'sometimes|nullable|string|max:80',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
