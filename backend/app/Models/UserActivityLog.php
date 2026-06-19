<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserActivityLog extends Model
{
    protected $table = 'user_activity_logs';

    protected $primaryKey = 'activity_log_id';

    protected $fillable = [
        'user_id',
        'module_code',
        'action_code',
        'description',
        'ip_address',
        'created_at',
    ];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

}
