<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $table = 'roles';

    protected $primaryKey = 'role_id';

    protected $fillable = [
        'role_code',
        'role_name',
        'description',
        'is_system_role',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'is_system_role' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function rolePermissions(): HasMany
    {
        return $this->hasMany(RolePermission::class, 'role_id', 'role_id');
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'role_permissions',
            'role_id',
            'permission_id',
            'role_id',
            'permission_id'
        )->where('permissions.is_active', true);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'user_roles',
            'role_id',
            'user_id',
            'role_id',
            'user_id'
        )
            ->withPivot(['user_role_id', 'assigned_by_user_id', 'assigned_at', 'is_active'])
            ->wherePivot('is_active', true);
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class, 'role_id', 'role_id');
    }

}
