<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradePartApprovalEvent extends Model
{
    public $timestamps = false;
    protected $primaryKey = 'grade_part_approval_event_id';
    protected $fillable = ['grade_part_approval_id', 'submission_version', 'action', 'old_values', 'new_values', 'performed_by_user_id', 'performed_at'];
    protected function casts(): array { return ['old_values' => 'array', 'new_values' => 'array', 'performed_at' => 'datetime']; }
    public function approval(): BelongsTo { return $this->belongsTo(GradePartApproval::class, 'grade_part_approval_id', 'grade_part_approval_id'); }
}
