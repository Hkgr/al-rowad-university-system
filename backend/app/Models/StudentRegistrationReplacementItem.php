<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentRegistrationReplacementItem extends Model
{
    protected $primaryKey = 'student_registration_replacement_item_id';
    protected $guarded = [];
    protected function casts(): array { return ['source_consumed_slot'=>'integer']; }
    public function request() { return $this->belongsTo(StudentRegistrationReplacementRequest::class, 'student_registration_replacement_request_id'); }
    public function sourceReview() { return $this->belongsTo(CourseOfferingMinimumEnrollmentReview::class, 'source_minimum_enrollment_review_id'); }
    public function sourceRegistration() { return $this->belongsTo(StudentCourseRegistration::class, 'source_student_course_registration_id'); }
    public function replacementOffering() { return $this->belongsTo(CourseOffering::class, 'replacement_course_offering_id'); }
    public function materializedRegistration() { return $this->belongsTo(StudentCourseRegistration::class, 'materialized_student_course_registration_id'); }
}
