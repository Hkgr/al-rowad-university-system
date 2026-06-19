<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplementaryExamResult extends Model
{
    protected $table = 'supplementary_exam_results';

    protected $primaryKey = 'supplementary_exam_result_id';

    protected $fillable = [
        'supplementary_exam_period_id',
        'student_course_registration_id',
        'theoretical_mark',
        'entered_by_user_id',
        'entered_at',
        'created_at',
    ];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'theoretical_mark' => 'decimal:2',
            'entered_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by_user_id', 'user_id');
    }

    public function supplementaryExamPeriod(): BelongsTo
    {
        return $this->belongsTo(SupplementaryExamPeriod::class, 'supplementary_exam_period_id', 'supplementary_exam_period_id');
    }

    public function studentCourseRegistration(): BelongsTo
    {
        return $this->belongsTo(StudentCourseRegistration::class, 'student_course_registration_id', 'student_course_registration_id');
    }

}
