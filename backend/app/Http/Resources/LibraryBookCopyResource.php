<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LibraryBookCopy */
class LibraryBookCopyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'library_book_copy_id' => $this->library_book_copy_id,
            'library_book_id' => $this->library_book_id,
            'copy_barcode' => $this->copy_barcode,
            'copy_status' => $this->copy_status,
            'shelf_location' => $this->shelf_location,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
