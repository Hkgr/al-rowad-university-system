<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LibraryBook */
class LibraryBookResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'library_book_id' => $this->library_book_id,
            'isbn' => $this->isbn,
            'title' => $this->title,
            'category_id' => $this->category_id,
            'publisher' => $this->publisher,
            'publication_year' => $this->publication_year,
            'language' => $this->language,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
