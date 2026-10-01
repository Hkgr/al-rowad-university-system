<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class UniversityEmailOperation extends Model
{
    protected $table = 'university_email_operations';
    protected $primaryKey = 'operation_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['generation' => 'integer', 'draft_revision' => 'integer', 'quota_mb' => 'integer',
        'active_slot' => 'integer', 'creation_slot' => 'integer', 'issued_by_user_id' => 'integer', 'initiated_by_user_id' => 'integer',
        'write_started_at' => 'datetime', 'verified_at' => 'datetime', 'cancelled_at' => 'datetime', 'cancelled_by_user_id' => 'integer'];
}
