<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentRegistrationModificationEvent extends Model
{
    public $timestamps = false;
    protected $table = 'student_registration_modification_events';
    protected $primaryKey = 'student_registration_modification_event_id';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'submission_version' => 'integer'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(StudentRegistrationModificationRequest::class, 'student_registration_modification_request_id', 'student_registration_modification_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id', 'user_id');
    }
}
