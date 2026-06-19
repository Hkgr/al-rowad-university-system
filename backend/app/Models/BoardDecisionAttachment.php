<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoardDecisionAttachment extends Model
{
    protected $table = 'board_decision_attachments';

    protected $primaryKey = 'attachment_id';

    protected $fillable = [
        'board_decision_id',
        'file_name',
        'file_url',
        'uploaded_by_user_id',
        'uploaded_at',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
        ];
    }

    public function boardDecision(): BelongsTo
    {
        return $this->belongsTo(BoardDecision::class, 'board_decision_id', 'board_decision_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id', 'user_id');
    }

}
