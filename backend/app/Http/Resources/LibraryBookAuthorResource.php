<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LibraryBookAuthor */
class LibraryBookAuthorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'book_author_id' => $this->book_author_id,
            'library_book_id' => $this->library_book_id,
            'library_author_id' => $this->library_author_id,
        ];
    }
}
