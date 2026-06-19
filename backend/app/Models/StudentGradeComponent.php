<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentGradeComponent extends Model
{
    protected $table = 'student_grade_components';

    protected $primaryKey = 'student_grade_component_id';

    protected $fillable = [
        'student_course_registration_id',
        'grade_component_id',
        'mark',
        'grade_status',
        'entered_by_user_id',
        'entered_at',
        'notes',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'mark' => 'decimal:2',
            'entered_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function gradeComponent(): BelongsTo
    {
        return $this->belongsTo(GradeComponent::class, 'grade_component_id', 'grade_component_id');
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by_user_id', 'user_id');
    }

    public function studentCourseRegistration(): BelongsTo
    {
        return $this->belongsTo(StudentCourseRegistration::class, 'student_course_registration_id', 'student_course_registration_id');
    }

    public function gradeAuditLogs(): HasMany
    {
        return $this->hasMany(GradeAuditLog::class, 'student_grade_component_id', 'student_grade_component_id');
    }

}
