<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentRegistrationWithdrawalEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'student_registration_withdrawal_events';

    protected $primaryKey = 'student_registration_withdrawal_event_id';

    protected $fillable = [
        'student_registration_withdrawal_request_id',
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
            StudentRegistrationWithdrawalRequest::class,
            'student_registration_withdrawal_request_id',
            'student_registration_withdrawal_request_id'
        );
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id', 'user_id');
    }
}
