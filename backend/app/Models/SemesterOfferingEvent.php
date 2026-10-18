<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SemesterOfferingEvent extends Model
{
    public const UPDATED_AT = null;
    public const CREATED_AT = 'occurred_at';

    protected $primaryKey = 'semester_offering_event_id';

    protected $fillable = [
        'semester_offering_request_id', 'submission_version', 'event_type',
        'actor_user_id', 'note', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['submission_version' => 'integer', 'occurred_at' => 'datetime'];
    }

    public function request(): BelongsTo { return $this->belongsTo(SemesterOfferingRequest::class, 'semester_offering_request_id', 'semester_offering_request_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id', 'user_id'); }
}
