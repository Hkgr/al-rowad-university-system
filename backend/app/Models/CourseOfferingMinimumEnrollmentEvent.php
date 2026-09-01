<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseOfferingMinimumEnrollmentEvent extends Model
{
    public $timestamps = false;
    protected $primaryKey = 'course_offering_minimum_enrollment_event_id';
    protected $guarded = [];
    protected function casts(): array { return ['created_at'=>'datetime']; }
    public function review() { return $this->belongsTo(CourseOfferingMinimumEnrollmentReview::class, 'course_offering_minimum_enrollment_review_id'); }
    public function actor() { return $this->belongsTo(User::class, 'actor_user_id', 'user_id'); }
}
