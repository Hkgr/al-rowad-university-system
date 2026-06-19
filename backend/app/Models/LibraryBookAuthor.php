<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LibraryBookAuthor extends Model
{
    protected $table = 'library_book_authors';

    protected $primaryKey = 'book_author_id';

    protected $fillable = [
        'library_book_id',
        'library_author_id',
    ];

    public $timestamps = false;

    public function libraryAuthor(): BelongsTo
    {
        return $this->belongsTo(LibraryAuthor::class, 'library_author_id', 'library_author_id');
    }

    public function libraryBook(): BelongsTo
    {
        return $this->belongsTo(LibraryBook::class, 'library_book_id', 'library_book_id');
    }

}
