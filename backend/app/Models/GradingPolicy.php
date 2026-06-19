<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradingPolicy extends Model
{
    protected $table = 'grading_policies';

    protected $primaryKey = 'grading_policy_id';

    protected $fillable = [
        'policy_name',
        'theoretical_max_mark',
        'practical_max_mark',
        'minimum_theoretical_mark',
        'minimum_practical_mark',
        'minimum_final_mark',
        'absence_deprivation_percentage',
        'is_default',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'theoretical_max_mark' => 'decimal:2',
            'practical_max_mark' => 'decimal:2',
            'minimum_theoretical_mark' => 'decimal:2',
            'minimum_practical_mark' => 'decimal:2',
            'minimum_final_mark' => 'decimal:2',
            'absence_deprivation_percentage' => 'decimal:2',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

}
