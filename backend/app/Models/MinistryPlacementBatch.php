<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MinistryPlacementBatch extends Model
{
    protected $table = 'ministry_placement_batches';

    protected $primaryKey = 'batch_id';

    protected $fillable = [
        'batch_name',
        'source_file_name',
        'academic_year_id',
        'import_date',
        'imported_by_user_id',
        'notes',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'import_date' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id', 'academic_year_id');
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_user_id', 'user_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(MinistryPlacementRecord::class, 'batch_id', 'batch_id');
    }
}
