<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradeApproval extends Model
{
    protected $table = 'grade_approvals';

    protected $primaryKey = 'grade_approval_id';

    protected $fillable = [
        'course_offering_id',
        'approval_status_id',
        'submitted_by_user_id',
        'submitted_at',
        'approved_by_user_id',
        'approval_role',
        'approval_date',
        'approval_notes',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'approval_date' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id', 'user_id');
    }

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id', 'course_offering_id');
    }

    public function approvalStatus(): BelongsTo
    {
        return $this->belongsTo(ApprovalStatus::class, 'approval_status_id', 'approval_status_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id', 'user_id');
    }

}
