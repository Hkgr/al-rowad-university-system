<?php

namespace App\Models;

use App\Support\AcademicRecordWorkflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentGraduationDecision extends Model
{
    protected $table = 'student_graduation_decisions';

    protected $primaryKey = 'student_graduation_decision_id';

    protected $fillable = [
        'student_id',
        'academic_program_id',
        'current_academic_level_id',
        'status',
        'decision_result',
        'current_slot',
        'cumulative_gpa_snapshot',
        'earned_hours_snapshot',
        'required_hours_snapshot',
        'eligibility_snapshot',
        'submitted_by_user_id',
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
            'current_slot' => 'integer',
            'cumulative_gpa_snapshot' => 'decimal:2',
            'earned_hours_snapshot' => 'integer',
            'required_hours_snapshot' => 'integer',
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
        return (int) $this->current_slot === AcademicRecordWorkflow::CURRENT_SLOT;
    }

    public function isSubmitted(): bool
    {
        return $this->status === AcademicRecordWorkflow::STATUS_SUBMITTED;
    }

    public function isReturned(): bool
    {
        return $this->status === AcademicRecordWorkflow::STATUS_RETURNED;
    }

    public function isApproved(): bool
    {
        return $this->status === AcademicRecordWorkflow::STATUS_APPROVED;
    }

    public function isMaterialized(): bool
    {
        return $this->materialized_at !== null;
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id', 'student_id');
    }

    public function academicProgram(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class, 'academic_program_id', 'academic_program_id');
    }

    public function currentAcademicLevel(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class, 'current_academic_level_id', 'academic_level_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id', 'user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id', 'user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(
            StudentGraduationEvent::class,
            'student_graduation_decision_id',
            'student_graduation_decision_id'
        );
    }
}
