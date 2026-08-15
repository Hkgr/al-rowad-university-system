<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseOfferingInstructor extends Model
{
    public const ROLES = [
        'theoretical',
        'practical',
    ];

    protected $table = 'course_offering_instructors';

    protected $primaryKey = 'course_offering_instructor_id';

    protected $fillable = [
        'course_offering_id',
        'faculty_member_id',
        'instructor_role',
        'is_primary',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id', 'course_offering_id');
    }

    public function facultyMember(): BelongsTo
    {
        return $this->belongsTo(FacultyMember::class, 'faculty_member_id', 'faculty_member_id');
    }
}
