<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoardMember extends Model
{
    protected $table = 'board_members';

    protected $primaryKey = 'board_member_id';

    protected $fillable = [
        'board_id',
        'employee_id',
        'full_name',
        'member_title',
        'start_date',
        'end_date',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class, 'board_id', 'board_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function meetingAttendees(): HasMany
    {
        return $this->hasMany(MeetingAttendee::class, 'board_member_id', 'board_member_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'board_member_id', 'board_member_id');
    }

}
