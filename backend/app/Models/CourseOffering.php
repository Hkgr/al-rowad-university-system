<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseOffering extends Model
{
    protected $table = 'course_offerings';

    protected $primaryKey = 'course_offering_id';

    protected $fillable = [
        'course_id',
        'academic_year_id',
        'semester_id',
        'department_id',
        'academic_program_id',
        'faculty_member_id',
        'capacity',
        'available_seats',
        'status',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id', 'course_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id', 'department_id');
    }

    public function facultyMember(): BelongsTo
    {
        return $this->belongsTo(FacultyMember::class, 'faculty_member_id', 'faculty_member_id');
    }

    public function academicProgram(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class, 'academic_program_id', 'academic_program_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'semester_id', 'semester_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id', 'academic_year_id');
    }

    public function attendanceSessions(): HasMany
    {
        return $this->hasMany(AttendanceSession::class, 'course_offering_id', 'course_offering_id');
    }

    public function gradeApprovals(): HasMany
    {
        return $this->hasMany(GradeApproval::class, 'course_offering_id', 'course_offering_id');
    }

    public function gradeComponents(): HasMany
    {
        return $this->hasMany(GradeComponent::class, 'course_offering_id', 'course_offering_id');
    }

    public function studentCourseRegistrations(): HasMany
    {
        return $this->hasMany(StudentCourseRegistration::class, 'course_offering_id', 'course_offering_id');
    }

}
