<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicLevel extends Model
{
    protected $table = 'academic_levels';

    protected $primaryKey = 'academic_level_id';

    protected $fillable = [
        'level_code',
        'level_name',
        'level_order',
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

    public function programCourses(): HasMany
    {
        return $this->hasMany(ProgramCourse::class, 'academic_level_id', 'academic_level_id');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'current_academic_level_id', 'academic_level_id');
    }

    public function studentAcademicTerms(): HasMany
    {
        return $this->hasMany(StudentAcademicTerm::class, 'academic_level_id', 'academic_level_id');
    }

}
