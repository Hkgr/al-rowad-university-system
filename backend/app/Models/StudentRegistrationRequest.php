<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentRegistrationRequest extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_APPROVED = 'approved';

    public const EDITABLE_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_RETURNED,
    ];

    protected $table = 'student_registration_requests';

    protected $primaryKey = 'student_registration_request_id';

    protected $fillable = [
        'student_id',
        'academic_year_id',
        'semester_id',
        'status',
        'submission_version',
        'student_notes',
        'advisor_user_id',
        'advisor_notes',
        'first_submitted_at',
        'last_submitted_at',
        'reviewed_at',
        'approved_at',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'submission_version' => 'integer',
            'first_submitted_at' => 'datetime',
            'last_submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true);
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
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
        return $this->hasMany(
            StudentRegistrationRequestItem::class,
            'student_registration_request_id',
            'student_registration_request_id'
        );
    }

    public function events(): HasMany
    {
        return $this->hasMany(
            StudentRegistrationRequestEvent::class,
            'student_registration_request_id',
            'student_registration_request_id'
        );
    }
}
