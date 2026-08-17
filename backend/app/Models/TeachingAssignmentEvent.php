<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeachingAssignmentEvent extends Model
{
    public $timestamps = false;

    public const UPDATED_AT = null;

    protected $table = 'teaching_assignment_events';

    protected $primaryKey = 'teaching_assignment_event_id';

    protected $fillable = [
        'teaching_assignment_request_id',
        'event_type',
        'actor_user_id',
        'submission_version',
        'notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'submission_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(TeachingAssignmentRequest::class, 'teaching_assignment_request_id', 'teaching_assignment_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id', 'user_id');
    }
}
