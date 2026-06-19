<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CoursePrerequisite extends Model
{
    protected $table = 'course_prerequisites';

    protected $primaryKey = 'course_prerequisite_id';

    protected $fillable = [
        'course_id',
        'prerequisite_course_id',
        'minimum_result_status_id',
        'created_at',
    ];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id', 'course_id');
    }

    public function minimumResultStatus(): BelongsTo
    {
        return $this->belongsTo(ResultStatus::class, 'minimum_result_status_id', 'result_status_id');
    }

    public function prerequisiteCourse(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'prerequisite_course_id', 'course_id');
    }

}
