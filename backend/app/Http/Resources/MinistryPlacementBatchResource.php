<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MinistryPlacementBatch */
class MinistryPlacementBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'batch_id' => $this->batch_id,
            'batch_name' => $this->batch_name,
            'source_file_name' => $this->source_file_name,
            'academic_year_id' => $this->academic_year_id,
            'import_date' => $this->import_date,
            'imported_by_user_id' => $this->imported_by_user_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'academic_year' => $this->whenLoaded('academicYear'),
            'imported_by' => $this->whenLoaded('importedBy'),
            'records' => MinistryPlacementRecordResource::collection($this->whenLoaded('records')),
            'records_count' => $this->whenCounted('records'),
        ];
    }
}
