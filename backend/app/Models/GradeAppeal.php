<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradeAppeal extends Model
{
    protected $table = 'grade_appeals';

    protected $primaryKey = 'grade_appeal_id';

    protected $fillable = [
        'student_id',
        'student_course_registration_id',
        'appeal_reason',
        'appeal_status_id',
        'submitted_at',
        'reviewed_by_user_id',
        'review_notes',
        'decision_date',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'decision_date' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function studentCourseRegistration(): BelongsTo
    {
        return $this->belongsTo(StudentCourseRegistration::class, 'student_course_registration_id', 'student_course_registration_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id', 'user_id');
    }

    public function appealStatus(): BelongsTo
    {
        return $this->belongsTo(AppealStatus::class, 'appeal_status_id', 'appeal_status_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id', 'student_id');
    }

}
