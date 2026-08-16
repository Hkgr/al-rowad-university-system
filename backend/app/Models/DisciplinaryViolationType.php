<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DisciplinaryViolationType extends Model
{
    protected $table = 'disciplinary_violation_types';

    protected $primaryKey = 'violation_type_id';

    protected $fillable = [
        'violation_code',
        'violation_name_ar',
        'bylaw_article_reference',
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

    public function studentDisciplinaryCases(): HasMany
    {
        return $this->hasMany(StudentDisciplinaryCase::class, 'violation_type_id', 'violation_type_id');
    }
}
