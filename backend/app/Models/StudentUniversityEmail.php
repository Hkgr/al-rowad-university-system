<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentUniversityEmail extends Model
{
    protected $table = 'student_university_emails';
    protected $primaryKey = 'university_email_id';
    protected $guarded = ['university_email_id'];
    protected function casts(): array { return ['quota_mb' => 'integer', 'revision' => 'integer']; }
}
