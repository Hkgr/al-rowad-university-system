<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentDisciplinaryCase extends Model
{
    protected $table = 'student_disciplinary_cases';

    protected $primaryKey = 'case_id';

    protected $fillable = [
        'student_id',
        'violation_type_id',
        'trigger_course_offering_id',
        'violation_description',
        'violation_date',
        'reported_by_user_id',
        'investigation_status',
        'investigation_date',
        'investigation_notes',
        'decided_by_authority',
        'decided_by_user_id',
        'decision_number',
        'decision_date',
        'penalty_type_id',
        'penalty_start_date',
        'penalty_end_date',
        'is_in_absentia',
        'guardian_notified_at',
        'case_status',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'violation_date' => 'date',
            'investigation_date' => 'date',
            'decision_date' => 'date',
            'penalty_start_date' => 'date',
            'penalty_end_date' => 'date',
            'is_in_absentia' => 'boolean',
            'guardian_notified_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id', 'student_id');
    }

    public function violationType(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryViolationType::class, 'violation_type_id', 'violation_type_id');
    }

    public function triggerCourseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'trigger_course_offering_id', 'course_offering_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id', 'user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id', 'user_id');
    }

    public function penaltyType(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryPenaltyType::class, 'penalty_type_id', 'penalty_type_id');
    }

    public function affectedCourses(): HasMany
    {
        return $this->hasMany(DisciplinaryCaseAffectedCourse::class, 'case_id', 'case_id');
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(DisciplinaryCaseAppeal::class, 'case_id', 'case_id');
    }
}
