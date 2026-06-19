<?php

namespace App\Models;

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
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
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

    public function supplementaryExamResults(): HasMany
    {
        return $this->hasMany(SupplementaryExamResult::class, 'supplementary_exam_period_id', 'supplementary_exam_period_id');
    }

}
