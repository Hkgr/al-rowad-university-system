<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicYear extends Model
{
    protected $table = 'academic_years';

    protected $primaryKey = 'academic_year_id';

    protected $fillable = [
        'year_name',
        'start_date',
        'end_date',
        'is_current',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_current' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function admissionApplications(): HasMany
    {
        return $this->hasMany(AdmissionApplication::class, 'academic_year_id', 'academic_year_id');
    }

    public function courseOfferings(): HasMany
    {
        return $this->hasMany(CourseOffering::class, 'academic_year_id', 'academic_year_id');
    }

    public function studentAcademicTerms(): HasMany
    {
        return $this->hasMany(StudentAcademicTerm::class, 'academic_year_id', 'academic_year_id');
    }

    public function studentCreditLimits(): HasMany
    {
        return $this->hasMany(StudentCreditLimit::class, 'academic_year_id', 'academic_year_id');
    }

    public function supplementaryExamPeriods(): HasMany
    {
        return $this->hasMany(SupplementaryExamPeriod::class, 'academic_year_id', 'academic_year_id');
    }

}
