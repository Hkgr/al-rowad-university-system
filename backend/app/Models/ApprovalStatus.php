<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalStatus extends Model
{
    protected $table = 'approval_statuses';

    protected $primaryKey = 'approval_status_id';

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

    public function gradeApprovals(): HasMany
    {
        return $this->hasMany(GradeApproval::class, 'approval_status_id', 'approval_status_id');
    }

}
