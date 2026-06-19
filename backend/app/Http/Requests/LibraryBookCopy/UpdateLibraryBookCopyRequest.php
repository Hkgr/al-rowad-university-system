<?php

namespace App\Http\Requests\LibraryBookCopy;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLibraryBookCopyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'library_book_id' => 'sometimes|nullable|integer|exists:library_books,library_book_id',
            'copy_barcode' => 'sometimes|nullable|string|max:80',
            'copy_status' => 'sometimes|nullable|string|max:50',
            'shelf_location' => 'sometimes|nullable|string|max:100',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
