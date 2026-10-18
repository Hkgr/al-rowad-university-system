<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DisciplinaryPenaltyType extends Model
{
    protected $table = 'disciplinary_penalty_types';

    protected $primaryKey = 'penalty_type_id';

    protected $fillable = [
        'penalty_code',
        'penalty_name_ar',
        'severity_order',
        'requires_investigation',
        'cascades_to_subsequent_courses',
        'min_authority_level',
        'bylaw_article_reference',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'severity_order' => 'integer',
            'requires_investigation' => 'boolean',
            'cascades_to_subsequent_courses' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function studentDisciplinaryCases(): HasMany
    {
        return $this->hasMany(StudentDisciplinaryCase::class, 'penalty_type_id', 'penalty_type_id');
    }
}
