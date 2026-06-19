<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LibraryBookCopy extends Model
{
    protected $table = 'library_book_copies';

    protected $primaryKey = 'library_book_copy_id';

    protected $fillable = [
        'library_book_id',
        'copy_barcode',
        'copy_status',
        'shelf_location',
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

    public function libraryBook(): BelongsTo
    {
        return $this->belongsTo(LibraryBook::class, 'library_book_id', 'library_book_id');
    }

    public function libraryBorrowings(): HasMany
    {
        return $this->hasMany(LibraryBorrowing::class, 'library_book_copy_id', 'library_book_copy_id');
    }

}
