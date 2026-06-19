<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    protected $table = 'courses';

    protected $primaryKey = 'course_id';

    protected $fillable = [
        'course_code',
        'course_name',
        'credit_hours',
        'theoretical_hours',
        'practical_hours',
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

    public function courseDepartments(): HasMany
    {
        return $this->hasMany(CourseDepartment::class, 'course_id', 'course_id');
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'course_departments', 'course_id', 'department_id', 'course_id', 'department_id')
            ->withPivot(['course_department_id', 'is_primary', 'created_at']);
    }

    public function courseInstructors(): HasMany
    {
        return $this->hasMany(CourseInstructor::class, 'course_id', 'course_id');
    }

    public function courseOfferings(): HasMany
    {
        return $this->hasMany(CourseOffering::class, 'course_id', 'course_id');
    }

    public function coursePrerequisites(): HasMany
    {
        return $this->hasMany(CoursePrerequisite::class, 'course_id', 'course_id');
    }

    public function prerequisites(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_prerequisites', 'course_id', 'prerequisite_course_id', 'course_id', 'course_id')
            ->withPivot(['course_prerequisite_id', 'minimum_result_status_id', 'created_at']);
    }

    public function coursePrerequisiteRecords(): HasMany
    {
        return $this->hasMany(CoursePrerequisite::class, 'prerequisite_course_id', 'course_id');
    }

    public function programCourses(): HasMany
    {
        return $this->hasMany(ProgramCourse::class, 'course_id', 'course_id');
    }

    public function academicPrograms(): BelongsToMany
    {
        return $this->belongsToMany(AcademicProgram::class, 'program_courses', 'course_id', 'academic_program_id', 'course_id', 'academic_program_id')
            ->withPivot(['program_course_id', 'academic_level_id', 'recommended_semester_id', 'course_type', 'is_active', 'created_at', 'updated_at']);
    }

}
