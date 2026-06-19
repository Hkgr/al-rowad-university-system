<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FacultyMember extends Model
{
    protected $table = 'faculty_members';

    protected $primaryKey = 'faculty_member_id';

    protected $fillable = [
        'employee_id',
        'academic_rank',
        'specialization',
        'office_location',
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function attendanceSessions(): HasMany
    {
        return $this->hasMany(AttendanceSession::class, 'faculty_member_id', 'faculty_member_id');
    }

    public function courseInstructors(): HasMany
    {
        return $this->hasMany(CourseInstructor::class, 'faculty_member_id', 'faculty_member_id');
    }

    public function courseOfferings(): HasMany
    {
        return $this->hasMany(CourseOffering::class, 'faculty_member_id', 'faculty_member_id');
    }

}
