<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Semester extends Model
{
    protected $table = 'semesters';

    protected $primaryKey = 'semester_id';

    protected $fillable = [
        'semester_code',
        'semester_name',
        'semester_order',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function courseOfferings(): HasMany
    {
        return $this->hasMany(CourseOffering::class, 'semester_id', 'semester_id');
    }

    public function programCourses(): HasMany
    {
        return $this->hasMany(ProgramCourse::class, 'recommended_semester_id', 'semester_id');
    }

    public function studentAcademicTerms(): HasMany
    {
        return $this->hasMany(StudentAcademicTerm::class, 'semester_id', 'semester_id');
    }

    public function studentCreditLimits(): HasMany
    {
        return $this->hasMany(StudentCreditLimit::class, 'semester_id', 'semester_id');
    }

    public function supplementaryExamPeriods(): HasMany
    {
        return $this->hasMany(SupplementaryExamPeriod::class, 'semester_id', 'semester_id');
    }

}
