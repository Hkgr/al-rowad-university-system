<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LibraryAuthor */
class LibraryAuthorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'library_author_id' => $this->library_author_id,
            'author_name' => $this->author_name,
            'biography' => $this->biography,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
