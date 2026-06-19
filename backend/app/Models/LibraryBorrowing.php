<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LibraryBorrowing extends Model
{
    protected $table = 'library_borrowings';

    protected $primaryKey = 'library_borrowing_id';

    protected $fillable = [
        'library_book_copy_id',
        'student_id',
        'employee_id',
        'borrowed_at',
        'due_at',
        'returned_at',
        'borrowing_status',
        'created_by_user_id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'borrowed_at' => 'datetime',
            'due_at' => 'datetime',
            'returned_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function libraryBookCopy(): BelongsTo
    {
        return $this->belongsTo(LibraryBookCopy::class, 'library_book_copy_id', 'library_book_copy_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'user_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id', 'student_id');
    }

}
