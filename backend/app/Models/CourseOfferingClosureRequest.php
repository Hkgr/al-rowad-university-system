<?php

namespace App\Models;

use App\Support\CourseOfferingClosureWorkflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CourseOfferingClosureRequest extends Model
{
    protected $table = 'course_offering_closure_requests';

    protected $primaryKey = 'course_offering_closure_request_id';

    protected $fillable = [
        'course_offering_id',
        'requested_by_user_id',
        'request_reason',
        'status',
        'submission_version',
        'current_slot',
        'course_id_snapshot',
        'academic_program_id_snapshot',
        'academic_year_id_snapshot',
        'semester_id_snapshot',
        'department_id_snapshot',
        'submitted_at',
        'approved_at',
        'materialized_at',
        'superseded_at',
        'superseded_by_request_id',
        'supersede_reason',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'submission_version' => 'integer',
            'current_slot' => 'integer',
            'course_id_snapshot' => 'integer',
            'academic_program_id_snapshot' => 'integer',
            'academic_year_id_snapshot' => 'integer',
            'semester_id_snapshot' => 'integer',
            'department_id_snapshot' => 'integer',
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
            && $this->status !== CourseOfferingClosureWorkflow::STATUS_SUPERSEDED;
    }

    public function isMaterialized(): bool
    {
        return $this->materialized_at !== null;
    }

    public function identityMatches(CourseOffering $offering): bool
    {
        return (int) $offering->course_id === (int) $this->course_id_snapshot
            && $this->sameNullableProgramId(
                $this->rawAttribute($offering, 'academic_program_id'),
                $this->rawAttribute($this, 'academic_program_id_snapshot')
            )
            && (int) $offering->academic_year_id === (int) $this->academic_year_id_snapshot
            && (int) $offering->semester_id === (int) $this->semester_id_snapshot;
    }

    /**
     * Canonical academic_program identity is NULL-safe for legacy Offerings.
     * Department snapshot is audit-only and is not compared here.
     */
    private function sameNullableProgramId(mixed $offeringProgramId, mixed $snapshotProgramId): bool
    {
        $offeringNull = $this->isNullProgramId($offeringProgramId);
        $snapshotNull = $this->isNullProgramId($snapshotProgramId);

        if ($offeringNull && $snapshotNull) {
            return true;
        }

        if ($offeringNull || $snapshotNull) {
            return false;
        }

        return (int) $offeringProgramId === (int) $snapshotProgramId;
    }

    private function isNullProgramId(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    private function rawAttribute(Model $model, string $attribute): mixed
    {
        $attributes = $model->getAttributes();
        if (array_key_exists($attribute, $attributes)) {
            return $attributes[$attribute];
        }

        return $model->getAttribute($attribute);
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
        return $this->belongsTo(self::class, 'superseded_by_request_id', 'course_offering_closure_request_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(
            CourseOfferingClosureReview::class,
            'course_offering_closure_request_id',
            'course_offering_closure_request_id'
        );
    }

    public function events(): HasMany
    {
        return $this->hasMany(
            CourseOfferingClosureEvent::class,
            'course_offering_closure_request_id',
            'course_offering_closure_request_id'
        )
            ->orderBy('created_at')
            ->orderBy('course_offering_closure_event_id');
    }

    public function minimumEnrollmentReview(): HasOne
    {
        return $this->hasOne(CourseOfferingMinimumEnrollmentReview::class, 'course_offering_closure_request_id', 'course_offering_closure_request_id');
    }

    public function currentVersionReviews()
    {
        return $this->reviews->where('submission_version', (int) $this->submission_version);
    }

    public function scientificReview(): ?CourseOfferingClosureReview
    {
        return $this->currentVersionReviews()
            ->firstWhere('review_authority', CourseOfferingClosureWorkflow::AUTHORITY_SCIENTIFIC);
    }

    public function administrativeReview(): ?CourseOfferingClosureReview
    {
        return $this->currentVersionReviews()
            ->firstWhere('review_authority', CourseOfferingClosureWorkflow::AUTHORITY_ADMINISTRATIVE);
    }
}
