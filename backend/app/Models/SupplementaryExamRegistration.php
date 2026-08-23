<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplementaryExamRegistration extends Model
{
    protected $primaryKey = 'supplementary_exam_registration_id';
    protected $guarded = [];
    protected function casts(): array { return ['registered_at'=>'datetime','cancelled_at'=>'datetime','eligibility_checked_at'=>'datetime']; }
    public function offering(): BelongsTo { return $this->belongsTo(SupplementaryExamOffering::class,'supplementary_exam_offering_id'); }
    public function student(): BelongsTo { return $this->belongsTo(Student::class,'student_id'); }
    public function originalRegistration(): BelongsTo { return $this->belongsTo(StudentCourseRegistration::class,'student_course_registration_id'); }
    public function events(): HasMany { return $this->hasMany(SupplementaryExamRegistrationEvent::class,'supplementary_exam_registration_id'); }
    public function gradeResult() { return $this->hasOne(SupplementaryExamGradeResult::class, 'supplementary_exam_registration_id'); }
    public function materialization() { return $this->hasOne(SupplementaryExamMaterialization::class, 'supplementary_exam_registration_id'); }
}
