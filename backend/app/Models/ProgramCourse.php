<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class ProgramCourse extends Model
{
    protected $table = 'program_courses';

    protected $primaryKey = 'program_course_id';

    protected $fillable = [
        'academic_program_id',
        'course_id',
        'academic_level_id',
        'recommended_semester_id',
        'course_type',
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

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id', 'course_id');
    }

    public function academicLevel(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class, 'academic_level_id', 'academic_level_id');
    }

    public function academicProgram(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class, 'academic_program_id', 'academic_program_id');
    }

    public function recommendedSemester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'recommended_semester_id', 'semester_id');
    }

    public function requirementMapping(): HasOne
    {
        return $this->hasOne(
            ProgramCourseRequirementGroup::class,
            'program_course_id',
            'program_course_id'
        );
    }

    public function requirementGroup(): HasOneThrough
    {
        return $this->hasOneThrough(
            AcademicRequirementGroup::class,
            ProgramCourseRequirementGroup::class,
            'program_course_id',
            'requirement_group_id',
            'program_course_id',
            'requirement_group_id'
        );
    }

}
