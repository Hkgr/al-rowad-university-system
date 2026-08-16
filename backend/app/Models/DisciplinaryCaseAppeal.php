<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryCaseAppeal extends Model
{
    protected $table = 'disciplinary_case_appeals';

    protected $primaryKey = 'appeal_id';

    protected $fillable = [
        'case_id',
        'submitted_at',
        'appeal_reason',
        'appeal_status_id',
        'reviewed_by_user_id',
        'decision_date',
        'decision_notes',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'decision_date' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function disciplinaryCase(): BelongsTo
    {
        return $this->belongsTo(StudentDisciplinaryCase::class, 'case_id', 'case_id');
    }

    public function appealStatus(): BelongsTo
    {
        return $this->belongsTo(AppealStatus::class, 'appeal_status_id', 'appeal_status_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id', 'user_id');
    }
}
