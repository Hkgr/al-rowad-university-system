<?php

namespace App\Http\Requests\LibraryBookCopy;

use Illuminate\Foundation\Http\FormRequest;

class StoreLibraryBookCopyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'library_book_id' => 'required|integer|exists:library_books,library_book_id',
            'copy_barcode' => 'required|string|max:80',
            'copy_status' => 'required|string|max:50',
            'shelf_location' => 'nullable|string|max:100',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
