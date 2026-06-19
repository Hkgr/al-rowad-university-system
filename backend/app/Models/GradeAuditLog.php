<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradeAuditLog extends Model
{
    protected $table = 'grade_audit_logs';

    protected $primaryKey = 'grade_audit_log_id';

    protected $fillable = [
        'student_grade_component_id',
        'old_mark',
        'new_mark',
        'changed_by_user_id',
        'change_reason',
        'changed_at',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'old_mark' => 'decimal:2',
            'new_mark' => 'decimal:2',
            'changed_at' => 'datetime',
        ];
    }

    public function studentGradeComponent(): BelongsTo
    {
        return $this->belongsTo(StudentGradeComponent::class, 'student_grade_component_id', 'student_grade_component_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id', 'user_id');
    }

}
