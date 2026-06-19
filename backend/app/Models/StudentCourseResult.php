<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentCourseResult extends Model
{
    protected $table = 'student_course_results';

    protected $primaryKey = 'student_course_result_id';

    protected $fillable = [
        'student_course_registration_id',
        'theoretical_total',
        'practical_total',
        'coursework_total',
        'final_mark',
        'result_status_id',
        'is_deprived',
        'calculated_at',
        'calculated_by_user_id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'theoretical_total' => 'decimal:2',
            'practical_total' => 'decimal:2',
            'coursework_total' => 'decimal:2',
            'final_mark' => 'decimal:2',
            'is_deprived' => 'boolean',
            'calculated_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function calculatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculated_by_user_id', 'user_id');
    }

    public function studentCourseRegistration(): BelongsTo
    {
        return $this->belongsTo(StudentCourseRegistration::class, 'student_course_registration_id', 'student_course_registration_id');
    }

    public function resultStatus(): BelongsTo
    {
        return $this->belongsTo(ResultStatus::class, 'result_status_id', 'result_status_id');
    }

}
