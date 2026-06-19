<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeetingAttendee extends Model
{
    protected $table = 'meeting_attendees';

    protected $primaryKey = 'meeting_attendee_id';

    protected $fillable = [
        'board_meeting_id',
        'board_member_id',
        'attendance_status',
        'notes',
    ];

    public $timestamps = false;

    public function boardMeeting(): BelongsTo
    {
        return $this->belongsTo(BoardMeeting::class, 'board_meeting_id', 'board_meeting_id');
    }

    public function boardMember(): BelongsTo
    {
        return $this->belongsTo(BoardMember::class, 'board_member_id', 'board_member_id');
    }

}
