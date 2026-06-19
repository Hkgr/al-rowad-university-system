<?php

namespace App\Http\Requests\LibraryBook;

use Illuminate\Foundation\Http\FormRequest;

class StoreLibraryBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'isbn' => 'nullable|string|max:50',
            'title' => 'required|string|max:250',
            'category_id' => 'nullable|integer|exists:library_categories,library_category_id',
            'publisher' => 'nullable|string|max:200',
            'publication_year' => 'nullable|integer',
            'language' => 'nullable|string|max:80',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
