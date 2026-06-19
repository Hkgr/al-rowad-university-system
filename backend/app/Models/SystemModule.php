<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SystemModule extends Model
{
    protected $table = 'system_modules';

    protected $primaryKey = 'module_id';

    protected $fillable = [
        'module_code',
        'module_name',
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

    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class, 'module_id', 'module_id');
    }

}
