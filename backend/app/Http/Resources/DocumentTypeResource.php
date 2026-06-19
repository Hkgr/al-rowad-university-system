<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DocumentType */
class DocumentTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'document_type_id' => $this->document_type_id,
            'type_code' => $this->type_code,
            'type_name' => $this->type_name,
            'is_required' => $this->is_required,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'student_documents' => $this->relationLoaded('studentDocuments') ? StudentDocumentResource::collection($this->studentDocuments) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
