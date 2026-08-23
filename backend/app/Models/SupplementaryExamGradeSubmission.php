<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplementaryExamGradeSubmission extends Model
{
    protected $primaryKey = 'supplementary_exam_grade_submission_id';
    protected $guarded = [];
    protected function casts(): array { return ['submission_version' => 'integer', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'published_at' => 'datetime']; }
    public function offering() { return $this->belongsTo(SupplementaryExamOffering::class, 'supplementary_exam_offering_id'); }
}
