<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplementaryExamGradeResult extends Model
{
    protected $primaryKey = 'supplementary_exam_grade_result_id';
    protected $guarded = [];
    protected function casts(): array { return ['theoretical_mark' => 'decimal:2', 'submission_version' => 'integer', 'published_at' => 'datetime']; }
    public function registration() { return $this->belongsTo(SupplementaryExamRegistration::class, 'supplementary_exam_registration_id'); }
    public function offering() { return $this->belongsTo(SupplementaryExamOffering::class, 'supplementary_exam_offering_id'); }
    public function events() { return $this->hasMany(SupplementaryExamGradeEvent::class, 'supplementary_exam_grade_result_id'); }
    public function materialization() { return $this->hasOne(SupplementaryExamMaterialization::class, 'supplementary_exam_grade_result_id'); }
}
