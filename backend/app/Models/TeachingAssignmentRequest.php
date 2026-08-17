<?php

namespace App\Models;

use App\Support\TeachingAssignmentWorkflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeachingAssignmentRequest extends Model
{
    protected $table = 'teaching_assignment_requests';

    protected $primaryKey = 'teaching_assignment_request_id';

    protected $fillable = [
        'course_offering_id',
        'faculty_member_id',
        'instructor_role',
        'status',
        'submission_version',
        'current_slot',
        'requested_by_user_id',
        'submitted_at',
        'approved_at',
        'superseded_at',
        'superseded_by_request_id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'submission_version' => 'integer',
            'current_slot' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'superseded_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function isCurrent(): bool
    {
        return (int) $this->current_slot === 1
            && $this->status !== TeachingAssignmentWorkflow::STATUS_SUPERSEDED;
    }

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id', 'course_offering_id');
    }

    public function facultyMember(): BelongsTo
    {
        return $this->belongsTo(FacultyMember::class, 'faculty_member_id', 'faculty_member_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id', 'user_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_request_id', 'teaching_assignment_request_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(TeachingAssignmentReview::class, 'teaching_assignment_request_id', 'teaching_assignment_request_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TeachingAssignmentEvent::class, 'teaching_assignment_request_id', 'teaching_assignment_request_id')
            ->orderBy('created_at')
            ->orderBy('teaching_assignment_event_id');
    }

    public function scientificReview(): ?TeachingAssignmentReview
    {
        return $this->reviews->firstWhere('review_authority', TeachingAssignmentWorkflow::AUTHORITY_SCIENTIFIC);
    }

    public function administrativeReview(): ?TeachingAssignmentReview
    {
        return $this->reviews->firstWhere('review_authority', TeachingAssignmentWorkflow::AUTHORITY_ADMINISTRATIVE);
    }
}
