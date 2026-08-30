<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SemesterOfferingRequest extends Model
{
    protected $primaryKey = 'semester_offering_request_id';

    protected $fillable = [
        'course_offering_id', 'program_course_id', 'course_type', 'is_selected',
        'minimum_enrollment', 'status', 'submission_version', 'created_by_user_id',
        'submitted_by_user_id', 'submitted_at', 'approved_at', 'materialized_at',
    ];

    protected function casts(): array
    {
        return [
            'is_selected' => 'boolean',
            'minimum_enrollment' => 'integer',
            'submission_version' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'materialized_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function courseOffering(): BelongsTo { return $this->belongsTo(CourseOffering::class, 'course_offering_id', 'course_offering_id'); }
    public function programCourse(): BelongsTo { return $this->belongsTo(ProgramCourse::class, 'program_course_id', 'program_course_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id', 'user_id'); }
    public function submittedBy(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by_user_id', 'user_id'); }
    public function reviews(): HasMany
    {
        return $this->hasMany(SemesterOfferingReview::class, 'semester_offering_request_id', 'semester_offering_request_id')
            ->orderBy('submission_version')
            ->orderBy('semester_offering_review_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SemesterOfferingEvent::class, 'semester_offering_request_id', 'semester_offering_request_id')
            ->orderBy('occurred_at')
            ->orderBy('semester_offering_event_id');
    }
}
