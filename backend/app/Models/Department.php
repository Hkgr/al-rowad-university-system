<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $table = 'departments';

    protected $primaryKey = 'department_id';

    protected $fillable = [
        'college_id',
        'organizational_unit_id',
        'department_code',
        'department_name',
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

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class, 'college_id', 'college_id');
    }

    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'organizational_unit_id', 'organizational_unit_id');
    }

    public function academicPrograms(): HasMany
    {
        return $this->hasMany(AcademicProgram::class, 'department_id', 'department_id');
    }

    public function courseDepartments(): HasMany
    {
        return $this->hasMany(CourseDepartment::class, 'department_id', 'department_id');
    }

    public function courseOfferings(): HasMany
    {
        return $this->hasMany(CourseOffering::class, 'department_id', 'department_id');
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_departments', 'department_id', 'course_id', 'department_id', 'course_id')
            ->withPivot(['course_department_id', 'is_primary', 'created_at']);
    }

}
