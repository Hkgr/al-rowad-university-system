<?php

namespace App\Models;

use App\Support\SupplementaryExamOfferingGovernance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplementaryExamOffering extends Model
{
    protected $table = 'supplementary_exam_offerings';

    protected $primaryKey = 'supplementary_exam_offering_id';

    protected $fillable = [
        'supplementary_exam_period_id',
        'academic_program_id',
        'course_id',
        'status',
        'opened_by_user_id',
        'opened_at',
        'closed_by_user_id',
        'closed_at',
    ];

    public function getRouteKeyName(): string
    {
        return 'supplementary_exam_offering_id';
    }

    protected function casts(): array
    {
        return [
            'status' => 'string',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(
            SupplementaryExamPeriod::class,
            'supplementary_exam_period_id',
            'supplementary_exam_period_id'
        );
    }

    public function academicProgram(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class, 'academic_program_id', 'academic_program_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id', 'course_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id', 'user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id', 'user_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(
            SupplementaryExamOfferingSource::class,
            'supplementary_exam_offering_id',
            'supplementary_exam_offering_id'
        );
    }

    public function events(): HasMany
    {
        return $this->hasMany(
            SupplementaryExamOfferingEvent::class,
            'supplementary_exam_offering_id',
            'supplementary_exam_offering_id'
        );
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(SupplementaryExamRegistration::class, 'supplementary_exam_offering_id', 'supplementary_exam_offering_id');
    }

    public function graderAssignments(): HasMany
    {
        return $this->hasMany(SupplementaryExamGraderAssignment::class, 'supplementary_exam_offering_id');
    }

    public function gradeResults(): HasMany
    {
        return $this->hasMany(SupplementaryExamGradeResult::class, 'supplementary_exam_offering_id');
    }

    public function materializations(): HasMany
    {
        return $this->hasMany(SupplementaryExamMaterialization::class, 'supplementary_exam_offering_id');
    }

    public function isOpen(): bool
    {
        return (string) $this->status === SupplementaryExamOfferingGovernance::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return (string) $this->status === SupplementaryExamOfferingGovernance::STATUS_CLOSED;
    }
}
