<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplementaryExamGradeEvent extends Model
{
    public $timestamps = false;
    protected $primaryKey = 'supplementary_exam_grade_event_id';
    protected $guarded = [];
    protected function casts(): array { return ['theoretical_mark' => 'decimal:2', 'submission_version' => 'integer', 'created_at' => 'datetime']; }
}
