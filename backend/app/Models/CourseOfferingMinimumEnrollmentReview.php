<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseOfferingMinimumEnrollmentReview extends Model
{
    protected $primaryKey = 'course_offering_minimum_enrollment_review_id';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['minimum_enrollment_snapshot'=>'integer','enrolled_count_snapshot'=>'integer','finalization_deadline_at'=>'datetime','finalized_at'=>'datetime','dean_recommended_at'=>'datetime','scientific_decided_at'=>'datetime','continued_at'=>'datetime','cancelled_at'=>'datetime','superseded_at'=>'datetime','affected_registration_count'=>'integer'];
    }

    public function semesterOfferingRequest() { return $this->belongsTo(SemesterOfferingRequest::class, 'semester_offering_request_id'); }
    public function courseOffering() { return $this->belongsTo(CourseOffering::class, 'course_offering_id'); }
    public function closureRequest() { return $this->belongsTo(CourseOfferingClosureRequest::class, 'course_offering_closure_request_id'); }
    public function events() { return $this->hasMany(CourseOfferingMinimumEnrollmentEvent::class, 'course_offering_minimum_enrollment_review_id')->orderBy('course_offering_minimum_enrollment_event_id'); }
    public function dean() { return $this->belongsTo(User::class, 'dean_user_id', 'user_id'); }
    public function scientificUser() { return $this->belongsTo(User::class, 'scientific_user_id', 'user_id'); }
}
