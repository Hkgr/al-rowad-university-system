<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResultStatus extends Model
{
    protected $table = 'result_statuses';

    protected $primaryKey = 'result_status_id';

    protected $fillable = [
        'status_code',
        'status_name',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function coursePrerequisites(): HasMany
    {
        return $this->hasMany(CoursePrerequisite::class, 'minimum_result_status_id', 'result_status_id');
    }

    public function studentCourseRegistrations(): HasMany
    {
        return $this->hasMany(StudentCourseRegistration::class, 'result_status_id', 'result_status_id');
    }

    public function studentCourseResults(): HasMany
    {
        return $this->hasMany(StudentCourseResult::class, 'result_status_id', 'result_status_id');
    }

}
