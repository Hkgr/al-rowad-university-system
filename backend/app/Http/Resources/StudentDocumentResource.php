<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StudentDocument */
class StudentDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'student_document_id' => $this->student_document_id,
            'student_id' => $this->student_id,
            'document_type_id' => $this->document_type_id,
            'file_name' => $this->file_name,
            'file_url' => $this->file_url,
            'verification_status' => $this->verification_status,
            'verified_by_user_id' => $this->verified_by_user_id,
            'verified_at' => $this->verified_at,
            'verification_notes' => $this->verification_notes,
            'uploaded_at' => $this->uploaded_at,
            'document_type' => DocumentTypeResource::make($this->whenLoaded('documentType')),
        ];
    }
}
