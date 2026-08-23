<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupplementaryExamMaterialization extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'supplementary_exam_materialization_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source_submission_version' => 'integer',
            'source_theoretical_mark' => 'decimal:2',
            'practical_components_snapshot' => 'array',
            'before_theoretical_total' => 'decimal:2',
            'before_practical_total' => 'decimal:2',
            'before_coursework_total' => 'decimal:2',
            'before_final_mark' => 'decimal:2',
            'before_is_deprived' => 'boolean',
            'after_theoretical_total' => 'decimal:2',
            'after_practical_total' => 'decimal:2',
            'after_coursework_total' => 'decimal:2',
            'after_final_mark' => 'decimal:2',
            'after_is_deprived' => 'boolean',
            'source_result_published_at' => 'datetime',
            'source_submission_published_at' => 'datetime',
            'source_registration_updated_at' => 'datetime',
            'source_result_updated_at' => 'datetime',
            'source_submission_updated_at' => 'datetime',
            'grade_approval_updated_at' => 'datetime',
            'before_calculated_at' => 'datetime',
            'before_result_announced_at' => 'datetime',
            'before_result_updated_at' => 'datetime',
            'before_registration_updated_at' => 'datetime',
            'after_calculated_at' => 'datetime',
            'after_result_announced_at' => 'datetime',
            'after_result_updated_at' => 'datetime',
            'after_registration_updated_at' => 'datetime',
            'materialized_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function supplementaryRegistration(): BelongsTo
    {
        return $this->belongsTo(SupplementaryExamRegistration::class, 'supplementary_exam_registration_id');
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(SupplementaryExamOffering::class, 'supplementary_exam_offering_id');
    }

    public function sourceResult(): BelongsTo
    {
        return $this->belongsTo(SupplementaryExamGradeResult::class, 'supplementary_exam_grade_result_id');
    }

    public function sourceEvent(): BelongsTo
    {
        return $this->belongsTo(SupplementaryExamGradeEvent::class, 'supplementary_exam_grade_event_id');
    }

    public function sourceSubmission(): BelongsTo
    {
        return $this->belongsTo(SupplementaryExamGradeSubmission::class, 'supplementary_exam_grade_submission_id');
    }

    public function originalRegistration(): BelongsTo
    {
        return $this->belongsTo(StudentCourseRegistration::class, 'student_course_registration_id');
    }

    public function targetResult(): BelongsTo
    {
        return $this->belongsTo(StudentCourseResult::class, 'student_course_result_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function gradingPolicy(): BelongsTo
    {
        return $this->belongsTo(GradingPolicy::class, 'grading_policy_id');
    }

    public function gradeApproval(): BelongsTo
    {
        return $this->belongsTo(GradeApproval::class, 'grade_approval_id');
    }

    public function preservedRegistrationStatus(): BelongsTo
    {
        return $this->belongsTo(RegistrationStatus::class, 'preserved_registration_status_id');
    }

    public function materializedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'materialized_by_user_id', 'user_id');
    }

    public function event(): HasOne
    {
        return $this->hasOne(SupplementaryExamMaterializationEvent::class, 'supplementary_exam_materialization_id');
    }
}
