<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RolePermission extends Model
{
    protected $table = 'role_permissions';

    protected $primaryKey = 'role_permission_id';

    protected $fillable = [
        'role_id',
        'permission_id',
        'granted_at',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
        ];
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permission_id', 'permission_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id', 'role_id');
    }

}
