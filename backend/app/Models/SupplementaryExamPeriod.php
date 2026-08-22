<?php

namespace App\Models;

use App\Support\SupplementaryExamPeriodGovernance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplementaryExamPeriod extends Model
{
    protected $table = 'supplementary_exam_periods';

    protected $primaryKey = 'supplementary_exam_period_id';

    protected $fillable = [
        'academic_year_id',
        'semester_id',
        'period_name',
        'start_date',
        'end_date',
        'decision_note',
    ];

    public function getRouteKeyName(): string
    {
        return 'supplementary_exam_period_id';
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
            'status' => 'string',
            'opened_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'semester_id', 'semester_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id', 'academic_year_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id', 'user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(
            SupplementaryExamPeriodEvent::class,
            'supplementary_exam_period_id',
            'supplementary_exam_period_id'
        );
    }

    public function supplementaryExamResults(): HasMany
    {
        return $this->hasMany(SupplementaryExamResult::class, 'supplementary_exam_period_id', 'supplementary_exam_period_id');
    }

    public function supplementaryExamOfferings(): HasMany
    {
        return $this->hasMany(
            SupplementaryExamOffering::class,
            'supplementary_exam_period_id',
            'supplementary_exam_period_id'
        );
    }

    public function isLegacy(): bool
    {
        return (string) $this->status === SupplementaryExamPeriodGovernance::STATUS_LEGACY
            || $this->status === null
            || $this->status === '';
    }
}
