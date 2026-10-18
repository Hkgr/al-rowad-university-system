<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplementaryExamOfferingEvent extends Model
{
    public $timestamps = false;

    public const UPDATED_AT = null;

    protected $table = 'supplementary_exam_offering_events';

    protected $primaryKey = 'supplementary_exam_offering_event_id';

    protected $fillable = [
        'supplementary_exam_offering_id',
        'event_type',
        'from_status',
        'to_status',
        'actor_user_id',
        'notes',
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

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id', 'user_id');
    }
}
