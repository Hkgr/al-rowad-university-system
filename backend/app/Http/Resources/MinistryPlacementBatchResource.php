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
            'batch_id' => (int) $this->batch_id,
            'batch_name' => $this->batch_name,
            'source_file_name' => $this->source_file_name,
            'academic_year_id' => (int) $this->academic_year_id,
            'academic_year' => $this->whenLoaded('academicYear', fn () => [
                'academic_year_id' => (int) $this->academicYear->academic_year_id,
                'year_name' => $this->academicYear->year_name,
            ]),
            'import_date' => $this->import_date?->toDateString(),
            'imported_by_user_id' => $this->imported_by_user_id === null ? null : (int) $this->imported_by_user_id,
            'imported_by' => $this->whenLoaded('importedBy', fn () => $this->importedBy === null ? null : [
                'user_id' => (int) $this->importedBy->user_id,
                'username' => $this->importedBy->username,
            ]),
            'notes' => $this->notes,
            'records_count' => $this->whenCounted('records'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
