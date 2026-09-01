<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentRegistrationModificationItem extends Model
{
    protected $table = 'student_registration_modification_items';
    protected $primaryKey = 'student_registration_modification_item_id';
    protected $guarded = [];

    public function request(): BelongsTo
    {
        return $this->belongsTo(StudentRegistrationModificationRequest::class, 'student_registration_modification_request_id', 'student_registration_modification_request_id');
    }

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id', 'course_offering_id');
    }

    public function sourceRegistration(): BelongsTo
    {
        return $this->belongsTo(StudentCourseRegistration::class, 'source_student_course_registration_id', 'student_course_registration_id');
    }

    public function materializedRegistration(): BelongsTo
    {
        return $this->belongsTo(StudentCourseRegistration::class, 'materialized_student_course_registration_id', 'student_course_registration_id');
    }
}
