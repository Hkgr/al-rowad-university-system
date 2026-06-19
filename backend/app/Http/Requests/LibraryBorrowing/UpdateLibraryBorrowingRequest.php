<?php

namespace App\Http\Requests\LibraryBorrowing;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLibraryBorrowingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'library_book_copy_id' => 'sometimes|nullable|integer|exists:library_book_copies,library_book_copy_id',
            'student_id' => 'sometimes|nullable|integer|exists:students,student_id',
            'employee_id' => 'sometimes|nullable|integer|exists:employees,employee_id',
            'borrowed_at' => 'sometimes|nullable|date',
            'due_at' => 'sometimes|nullable|date',
            'returned_at' => 'sometimes|nullable|date',
            'borrowing_status' => 'sometimes|nullable|string|max:50',
            'created_by_user_id' => 'sometimes|nullable|integer|exists:users,user_id',
            'created_at' => 'sometimes|nullable|date',
            'updated_at' => 'sometimes|nullable|date',
        ];
    }
}
