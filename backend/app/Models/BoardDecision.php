<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoardDecision extends Model
{
    protected $table = 'board_decisions';

    protected $primaryKey = 'board_decision_id';

    protected $fillable = [
        'board_meeting_id',
        'decision_number',
        'decision_title',
        'decision_text',
        'decision_date',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'decision_date' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function boardMeeting(): BelongsTo
    {
        return $this->belongsTo(BoardMeeting::class, 'board_meeting_id', 'board_meeting_id');
    }

    public function boardDecisionAttachments(): HasMany
    {
        return $this->hasMany(BoardDecisionAttachment::class, 'board_decision_id', 'board_decision_id');
    }

}
