<?php

namespace App\Http\Requests\LibraryBorrowing;

use Illuminate\Foundation\Http\FormRequest;

class StoreLibraryBorrowingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'library_book_copy_id' => 'required|integer|exists:library_book_copies,library_book_copy_id',
            'student_id' => 'nullable|integer|exists:students,student_id',
            'employee_id' => 'nullable|integer|exists:employees,employee_id',
            'borrowed_at' => 'required|date',
            'due_at' => 'required|date',
            'returned_at' => 'nullable|date',
            'borrowing_status' => 'required|string|max:50',
            'created_by_user_id' => 'nullable|integer|exists:users,user_id',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
        ];
    }
}
