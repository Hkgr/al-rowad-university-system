<?php

namespace App\Models;

use App\Support\ExceptionalOpeningWorkflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseOfferingExceptionRequest extends Model
{
    protected $table = 'course_offering_exception_requests';

    protected $primaryKey = 'course_offering_exception_request_id';

    protected $fillable = [
        'course_offering_id',
        'requested_by_user_id',
        'reason',
        'status',
        'submission_version',
        'current_slot',
        'snapshot_course_id',
        'snapshot_academic_program_id',
        'snapshot_academic_year_id',
        'snapshot_semester_id',
        'snapshot_department_id',
        'submitted_at',
        'approved_at',
        'materialized_at',
        'superseded_at',
        'superseded_by_request_id',
        'superseded_reason',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'submission_version' => 'integer',
            'current_slot' => 'integer',
            'snapshot_course_id' => 'integer',
            'snapshot_academic_program_id' => 'integer',
            'snapshot_academic_year_id' => 'integer',
            'snapshot_semester_id' => 'integer',
            'snapshot_department_id' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'materialized_at' => 'datetime',
            'superseded_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function isCurrent(): bool
    {
        return (int) $this->current_slot === 1
            && $this->status !== ExceptionalOpeningWorkflow::STATUS_SUPERSEDED;
    }

    public function isMaterialized(): bool
    {
        return $this->materialized_at !== null;
    }

    public function identityMatches(CourseOffering $offering): bool
    {
        return (int) $offering->course_id === (int) $this->snapshot_course_id
            && (int) $offering->academic_program_id === (int) $this->snapshot_academic_program_id
            && (int) $offering->academic_year_id === (int) $this->snapshot_academic_year_id
            && (int) $offering->semester_id === (int) $this->snapshot_semester_id;
    }

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id', 'course_offering_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id', 'user_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_request_id', 'course_offering_exception_request_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(
            CourseOfferingExceptionReview::class,
            'course_offering_exception_request_id',
            'course_offering_exception_request_id'
        );
    }

    public function events(): HasMany
    {
        return $this->hasMany(
            CourseOfferingExceptionEvent::class,
            'course_offering_exception_request_id',
            'course_offering_exception_request_id'
        )
            ->orderBy('created_at')
            ->orderBy('course_offering_exception_event_id');
    }

    public function currentVersionReviews()
    {
        return $this->reviews->where('submission_version', (int) $this->submission_version);
    }

    public function scientificReview(): ?CourseOfferingExceptionReview
    {
        return $this->currentVersionReviews()
            ->firstWhere('review_authority', ExceptionalOpeningWorkflow::AUTHORITY_SCIENTIFIC);
    }

    public function administrativeReview(): ?CourseOfferingExceptionReview
    {
        return $this->currentVersionReviews()
            ->firstWhere('review_authority', ExceptionalOpeningWorkflow::AUTHORITY_ADMINISTRATIVE);
    }
}
