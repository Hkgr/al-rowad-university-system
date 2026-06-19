<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicProgram extends Model
{
    protected $table = 'academic_programs';

    protected $primaryKey = 'academic_program_id';

    protected $fillable = [
        'department_id',
        'program_code',
        'program_name',
        'degree_level',
        'total_credit_hours',
        'duration_years',
        'description',
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

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id', 'department_id');
    }

    public function admissionApplications(): HasMany
    {
        return $this->hasMany(AdmissionApplication::class, 'academic_program_id', 'academic_program_id');
    }

    public function courseOfferings(): HasMany
    {
        return $this->hasMany(CourseOffering::class, 'academic_program_id', 'academic_program_id');
    }

    public function programCourses(): HasMany
    {
        return $this->hasMany(ProgramCourse::class, 'academic_program_id', 'academic_program_id');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'academic_program_id', 'academic_program_id');
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'program_courses', 'academic_program_id', 'course_id', 'academic_program_id', 'course_id')
            ->withPivot(['program_course_id', 'academic_level_id', 'recommended_semester_id', 'course_type', 'is_active', 'created_at', 'updated_at']);
    }

}
