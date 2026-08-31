<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentRegistrationRequestEvent extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_DRAFT_CREATED = 'draft_created';

    public const TYPE_ITEM_ADDED = 'item_added';

    public const TYPE_ITEM_REMOVED = 'item_removed';

    public const TYPE_SUBMITTED = 'submitted';

    public const TYPE_RETURNED = 'returned';

    public const TYPE_RESUBMITTED = 'resubmitted';

    public const TYPE_APPROVED = 'approved';

    public const TYPE_EXPIRED_DEADLINE = 'expired_deadline';

    protected $table = 'student_registration_request_events';

    protected $primaryKey = 'student_registration_request_event_id';

    protected $fillable = [
        'student_registration_request_id',
        'event_type',
        'actor_user_id',
        'from_status',
        'to_status',
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
        return $this->belongsTo(
            StudentRegistrationRequest::class,
            'student_registration_request_id',
            'student_registration_request_id'
        );
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id', 'user_id');
    }
}
