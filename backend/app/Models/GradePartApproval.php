<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradePartApproval extends Model
{
    public const PARTS = ['practical', 'theoretical'];
    public const STATUSES = ['draft', 'submitted', 'returned', 'approved'];

    protected $primaryKey = 'grade_part_approval_id';
    protected $fillable = ['course_offering_id', 'component_type', 'status', 'submission_version', 'submitted_by_user_id', 'submitted_at', 'reviewed_by_user_id', 'reviewed_at', 'review_notes'];
    protected function casts(): array { return ['submitted_at' => 'datetime', 'reviewed_at' => 'datetime']; }
    public function courseOffering(): BelongsTo { return $this->belongsTo(CourseOffering::class, 'course_offering_id', 'course_offering_id'); }
    public function submittedBy(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by_user_id', 'user_id'); }
    public function reviewedBy(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by_user_id', 'user_id'); }
    public function events(): HasMany { return $this->hasMany(GradePartApprovalEvent::class, 'grade_part_approval_id', 'grade_part_approval_id'); }
}
