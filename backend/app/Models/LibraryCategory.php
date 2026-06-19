<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LibraryCategory extends Model
{
    protected $table = 'library_categories';

    protected $primaryKey = 'library_category_id';

    protected $fillable = [
        'category_name',
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

    public function libraryBooks(): HasMany
    {
        return $this->hasMany(LibraryBook::class, 'category_id', 'library_category_id');
    }

}
