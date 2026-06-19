<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LibraryBorrowing */
class LibraryBorrowingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'library_borrowing_id' => $this->library_borrowing_id,
            'library_book_copy_id' => $this->library_book_copy_id,
            'student_id' => $this->student_id,
            'employee_id' => $this->employee_id,
            'borrowed_at' => $this->borrowed_at,
            'due_at' => $this->due_at,
            'returned_at' => $this->returned_at,
            'borrowing_status' => $this->borrowing_status,
            'created_by_user_id' => $this->created_by_user_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
