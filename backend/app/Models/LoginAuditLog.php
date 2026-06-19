<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoginAuditLog extends Model
{
    protected $table = 'login_audit_logs';

    protected $primaryKey = 'login_audit_id';

    protected $fillable = [
        'user_id',
        'username_attempted',
        'login_status',
        'ip_address',
        'user_agent',
        'attempted_at',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

}
