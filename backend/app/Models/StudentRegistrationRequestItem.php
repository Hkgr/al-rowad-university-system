<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentRegistrationRequestItem extends Model
{
    protected $table = 'student_registration_request_items';

    protected $primaryKey = 'student_registration_request_item_id';

    protected $fillable = [
        'student_registration_request_id',
        'course_offering_id',
        'student_course_registration_id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(
            StudentRegistrationRequest::class,
            'student_registration_request_id',
            'student_registration_request_id'
        );
    }

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id', 'course_offering_id');
    }

    public function studentCourseRegistration(): BelongsTo
    {
        return $this->belongsTo(
            StudentCourseRegistration::class,
            'student_course_registration_id',
            'student_course_registration_id'
        );
    }
}
