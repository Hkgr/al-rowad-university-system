<?php

namespace App\Models;

use App\Support\RegistrationLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentRegistrationWithdrawalRequest extends Model
{
    protected $table = 'student_registration_withdrawal_requests';

    protected $primaryKey = 'student_registration_withdrawal_request_id';

    protected $fillable = [
        'student_course_registration_id',
        'student_id',
        'status',
        'submission_version',
        'current_slot',
        'request_reason',
        'requested_by_user_id',
        'submitted_at',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_notes',
        'approved_at',
        'materialized_at',
        'superseded_at',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'submission_version' => 'integer',
            'current_slot' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'materialized_at' => 'datetime',
            'superseded_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function isCurrent(): bool
    {
        return (int) $this->current_slot === RegistrationLifecycle::CURRENT_SLOT;
    }

    public function isSubmitted(): bool
    {
        return $this->status === RegistrationLifecycle::STATUS_SUBMITTED;
    }

    public function isReturned(): bool
    {
        return $this->status === RegistrationLifecycle::STATUS_RETURNED;
    }

    public function isApproved(): bool
    {
        return $this->status === RegistrationLifecycle::STATUS_APPROVED;
    }

    public function isMaterialized(): bool
    {
        return $this->materialized_at !== null;
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(
            StudentCourseRegistration::class,
            'student_course_registration_id',
            'student_course_registration_id'
        );
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id', 'student_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id', 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id', 'user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(
            StudentRegistrationWithdrawalEvent::class,
            'student_registration_withdrawal_request_id',
            'student_registration_withdrawal_request_id'
        );
    }
}
