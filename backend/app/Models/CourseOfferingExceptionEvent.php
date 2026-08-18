<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseOfferingExceptionEvent extends Model
{
    public $timestamps = false;

    public const UPDATED_AT = null;

    protected $table = 'course_offering_exception_events';

    protected $primaryKey = 'course_offering_exception_event_id';

    protected $fillable = [
        'course_offering_exception_request_id',
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
        return $this->belongsTo(
            CourseOfferingExceptionRequest::class,
            'course_offering_exception_request_id',
            'course_offering_exception_request_id'
        );
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id', 'user_id');
    }
}
