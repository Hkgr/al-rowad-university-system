<?php

namespace App\Http\Requests\LibraryBookAuthor;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLibraryBookAuthorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'library_book_id' => 'sometimes|nullable|integer|exists:library_books,library_book_id',
            'library_author_id' => 'sometimes|nullable|integer|exists:library_authors,library_author_id',
        ];
    }
}
