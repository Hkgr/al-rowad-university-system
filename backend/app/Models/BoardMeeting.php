<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoardMeeting extends Model
{
    protected $table = 'board_meetings';

    protected $primaryKey = 'board_meeting_id';

    protected $fillable = [
        'board_id',
        'meeting_title',
        'meeting_date',
        'location',
        'agenda',
        'minutes',
        'created_by_user_id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'meeting_date' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class, 'board_id', 'board_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'user_id');
    }

    public function boardDecisions(): HasMany
    {
        return $this->hasMany(BoardDecision::class, 'board_meeting_id', 'board_meeting_id');
    }

    public function meetingAttendees(): HasMany
    {
        return $this->hasMany(MeetingAttendee::class, 'board_meeting_id', 'board_meeting_id');
    }

}
