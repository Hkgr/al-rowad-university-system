<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplementaryExamMaterializationEvent extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'supplementary_exam_materialization_event_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source_submission_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function materialization(): BelongsTo
    {
        return $this->belongsTo(SupplementaryExamMaterialization::class, 'supplementary_exam_materialization_id');
    }
}
