<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentStatus extends Model
{
    protected $table = 'student_statuses';

    protected $primaryKey = 'student_status_id';

    protected $fillable = [
        'status_code',
        'status_name',
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

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'student_status_id', 'student_status_id');
    }

}
