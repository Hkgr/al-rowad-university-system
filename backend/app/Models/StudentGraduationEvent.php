<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentGraduationEvent extends Model
{
    public $timestamps = false;

    protected $table = 'student_graduation_events';

    protected $primaryKey = 'student_graduation_event_id';

    protected $fillable = [
        'student_graduation_decision_id',
        'event_type',
        'actor_user_id',
        'from_status',
        'to_status',
        'notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(
            StudentGraduationDecision::class,
            'student_graduation_decision_id',
            'student_graduation_decision_id'
        );
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id', 'user_id');
    }
}
