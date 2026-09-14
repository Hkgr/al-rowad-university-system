<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicPlanVersion extends Model
{
    protected $table = 'academic_plan_versions';
    protected $primaryKey = 'academic_plan_version_id';
    protected $guarded = ['academic_plan_version_id'];

    protected function casts(): array
    {
        return ['total_credit_hours' => 'integer', 'version_number' => 'integer', 'approved_at' => 'datetime', 'fixed_at' => 'datetime'];
    }

    public function academicProgram(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class, 'academic_program_id');
    }
}
