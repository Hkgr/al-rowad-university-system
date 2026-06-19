<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentDocument extends Model
{
    protected $table = 'student_documents';

    protected $primaryKey = 'student_document_id';

    protected $fillable = [
        'student_id',
        'document_type_id',
        'file_name',
        'file_url',
        'verification_status',
        'verified_by_user_id',
        'verified_at',
        'verification_notes',
        'uploaded_at',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'uploaded_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id', 'student_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id', 'document_type_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id', 'user_id');
    }

}
