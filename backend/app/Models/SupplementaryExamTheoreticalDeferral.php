<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplementaryExamTheoreticalDeferral extends Model
{
    protected $primaryKey = 'supplementary_exam_theoretical_deferral_id';
    protected $guarded = [];
    protected function casts(): array { return ['declared_at'=>'datetime','cancelled_at'=>'datetime','superseded_at'=>'datetime']; }
    public function offering(): BelongsTo { return $this->belongsTo(SupplementaryExamOffering::class, 'supplementary_exam_offering_id'); }
    public function registration(): BelongsTo { return $this->belongsTo(StudentCourseRegistration::class, 'student_course_registration_id'); }
    public function events(): HasMany { return $this->hasMany(SupplementaryExamTheoreticalDeferralEvent::class, 'supplementary_exam_theoretical_deferral_id'); }
}
