<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LibraryBook extends Model
{
    protected $table = 'library_books';

    protected $primaryKey = 'library_book_id';

    protected $fillable = [
        'isbn',
        'title',
        'category_id',
        'publisher',
        'publication_year',
        'language',
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

    public function libraryCategory(): BelongsTo
    {
        return $this->belongsTo(LibraryCategory::class, 'category_id', 'library_category_id');
    }

    public function libraryBookAuthors(): HasMany
    {
        return $this->hasMany(LibraryBookAuthor::class, 'library_book_id', 'library_book_id');
    }

    public function libraryBookCopys(): HasMany
    {
        return $this->hasMany(LibraryBookCopy::class, 'library_book_id', 'library_book_id');
    }

}
