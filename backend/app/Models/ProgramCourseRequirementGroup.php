<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgramCourseRequirementGroup extends Model
{
    protected $table = 'program_course_requirement_groups';

    protected $primaryKey = 'program_course_requirement_group_id';

    protected $fillable = [
        'program_course_id',
        'requirement_group_id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function programCourse(): BelongsTo
    {
        return $this->belongsTo(ProgramCourse::class, 'program_course_id', 'program_course_id');
    }

    public function requirementGroup(): BelongsTo
    {
        return $this->belongsTo(
            AcademicRequirementGroup::class,
            'requirement_group_id',
            'requirement_group_id'
        );
    }
}
