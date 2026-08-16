<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicRequirementGroup extends Model
{
    public const SCOPE_UNIVERSITY = 'university';

    public const SCOPE_COLLEGE = 'college';

    public const SCOPE_DEPARTMENT = 'department';

    public const TYPE_MANDATORY = 'mandatory';

    public const TYPE_ELECTIVE = 'elective';

    protected $table = 'academic_requirement_groups';

    protected $primaryKey = 'requirement_group_id';

    protected $fillable = [
        'academic_program_id',
        'group_code',
        'group_name',
        'requirement_scope',
        'requirement_type',
        'required_credit_hours',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'required_credit_hours' => 'integer',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function academicProgram(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class, 'academic_program_id', 'academic_program_id');
    }

    public function programCourseMappings(): HasMany
    {
        return $this->hasMany(
            ProgramCourseRequirementGroup::class,
            'requirement_group_id',
            'requirement_group_id'
        );
    }
}
