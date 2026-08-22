<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplementaryExamOfferingSource extends Model
{
    public $timestamps = false;

    public const UPDATED_AT = null;

    protected $table = 'supplementary_exam_offering_sources';

    protected $primaryKey = 'supplementary_exam_offering_source_id';

    protected $fillable = [
        'supplementary_exam_offering_id',
        'course_offering_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(
            SupplementaryExamOffering::class,
            'supplementary_exam_offering_id',
            'supplementary_exam_offering_id'
        );
    }

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id', 'course_offering_id');
    }
}
