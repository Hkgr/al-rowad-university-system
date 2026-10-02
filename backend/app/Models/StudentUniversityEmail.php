<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentUniversityEmail extends Model
{
    protected $table = 'student_university_emails';
    protected $primaryKey = 'university_email_id';
    // Explicit columns remain fillable after an additive migration even in a long-lived
    // process that previously cached the old table's guardable-column inventory.
    protected $fillable = ['student_id', 'english_first_name', 'email_address', 'quota_mb', 'provisioning_status',
        'handover_status', 'revision', 'created_by_user_id', 'updated_by_user_id', 'creation_operation_id',
        'credential_operation_id', 'linkage_origin', 'remote_snapshot', 'remote_checked_at', 'lifecycle_revision',
        'deleted_at', 'deleted_by_user_id'];
    protected function casts(): array { return ['quota_mb' => 'integer', 'revision' => 'integer', 'lifecycle_revision' => 'integer', 'deleted_at' => 'datetime', 'deleted_by_user_id' => 'integer', 'remote_snapshot' => 'array', 'remote_checked_at' => 'datetime']; }
}
