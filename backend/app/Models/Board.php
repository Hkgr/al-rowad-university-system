<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Board extends Model
{
    protected $table = 'boards';

    protected $primaryKey = 'board_id';

    protected $fillable = [
        'board_code',
        'board_name',
        'organizational_unit_id',
        'description',
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

    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'organizational_unit_id', 'organizational_unit_id');
    }

    public function boardMeetings(): HasMany
    {
        return $this->hasMany(BoardMeeting::class, 'board_id', 'board_id');
    }

    public function boardMembers(): HasMany
    {
        return $this->hasMany(BoardMember::class, 'board_id', 'board_id');
    }

}
