<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LibraryCategory */
class LibraryCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'library_category_id' => $this->library_category_id,
            'category_name' => $this->category_name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
