<?php

namespace App\Models;

use App\Support\RegistrationModificationWorkflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentRegistrationModificationRequest extends Model
{
    protected $table = 'student_registration_modification_requests';
    protected $primaryKey = 'student_registration_modification_request_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'submission_version' => 'integer',
            'current_slot' => 'integer',
            'first_submitted_at' => 'datetime',
            'last_submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'expired_at' => 'datetime',
            'superseded_at' => 'datetime',
            'materialized_at' => 'datetime',
            'registered_hours_before_approval' => 'integer',
            'removed_hours_at_approval' => 'integer',
            'added_hours_at_approval' => 'integer',
            'projected_hours_at_approval' => 'integer',
            'max_allowed_hours_at_approval' => 'integer',
            'remaining_hours_after_approval' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function isCurrent(): bool
    {
        return (int) $this->current_slot === RegistrationModificationWorkflow::CURRENT_SLOT;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [
            RegistrationModificationWorkflow::STATUS_DRAFT,
            RegistrationModificationWorkflow::STATUS_RETURNED,
        ], true);
    }

    public function initialRequest(): BelongsTo
    {
        return $this->belongsTo(StudentRegistrationRequest::class, 'initial_registration_request_id', 'student_registration_request_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id', 'student_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id', 'academic_year_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'semester_id', 'semester_id');
    }

    public function advisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advisor_user_id', 'user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StudentRegistrationModificationItem::class, 'student_registration_modification_request_id', 'student_registration_modification_request_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(StudentRegistrationModificationEvent::class, 'student_registration_modification_request_id', 'student_registration_modification_request_id');
    }
}
