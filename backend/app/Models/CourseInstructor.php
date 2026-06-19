<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseInstructor extends Model
{
    protected $table = 'course_instructors';

    protected $primaryKey = 'course_instructor_id';

    protected $fillable = [
        'course_id',
        'faculty_member_id',
        'is_primary',
        'is_active',
        'created_at',
    ];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id', 'course_id');
    }

    public function facultyMember(): BelongsTo
    {
        return $this->belongsTo(FacultyMember::class, 'faculty_member_id', 'faculty_member_id');
    }

}
