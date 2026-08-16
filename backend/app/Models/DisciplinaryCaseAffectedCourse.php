<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryCaseAffectedCourse extends Model
{
    protected $table = 'disciplinary_case_affected_courses';

    protected $primaryKey = 'affected_course_id';

    protected $fillable = [
        'case_id',
        'course_offering_id',
        'previous_theoretical_mark',
        'previous_practical_mark',
        'previous_coursework_mark',
        'previous_final_mark',
        'previous_result_status_id',
        'applied_at',
        'reverted_at',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_theoretical_mark' => 'decimal:2',
            'previous_practical_mark' => 'decimal:2',
            'previous_coursework_mark' => 'decimal:2',
            'previous_final_mark' => 'decimal:2',
            'applied_at' => 'datetime',
            'reverted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function disciplinaryCase(): BelongsTo
    {
        return $this->belongsTo(StudentDisciplinaryCase::class, 'case_id', 'case_id');
    }

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id', 'course_offering_id');
    }

    public function previousResultStatus(): BelongsTo
    {
        return $this->belongsTo(ResultStatus::class, 'previous_result_status_id', 'result_status_id');
    }
}
