<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LibraryAuthor extends Model
{
    protected $table = 'library_authors';

    protected $primaryKey = 'library_author_id';

    protected $fillable = [
        'author_name',
        'biography',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function libraryBookAuthors(): HasMany
    {
        return $this->hasMany(LibraryBookAuthor::class, 'library_author_id', 'library_author_id');
    }

}
