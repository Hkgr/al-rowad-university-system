<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentType extends Model
{
    protected $table = 'document_types';

    protected $primaryKey = 'document_type_id';

    protected $fillable = [
        'type_code',
        'type_name',
        'is_required',
        'description',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function studentDocuments(): HasMany
    {
        return $this->hasMany(StudentDocument::class, 'document_type_id', 'document_type_id');
    }

}
