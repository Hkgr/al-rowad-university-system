<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentRegistrationReplacementRequest extends Model
{
    protected $primaryKey = 'student_registration_replacement_request_id';
    protected $guarded = [];
    protected function casts(): array
    {
        return ['submission_version'=>'integer','current_slot'=>'integer','first_submitted_at'=>'datetime','last_submitted_at'=>'datetime','reviewed_at'=>'datetime','approved_at'=>'datetime','expired_at'=>'datetime','superseded_at'=>'datetime','materialized_at'=>'datetime'];
    }
    public function student() { return $this->belongsTo(Student::class); }
    public function academicYear() { return $this->belongsTo(AcademicYear::class, 'academic_year_id'); }
    public function semester() { return $this->belongsTo(Semester::class, 'semester_id'); }
    public function calendarEvent() { return $this->belongsTo(AcademicCalendarEvent::class, 'academic_calendar_event_id'); }
    public function items() { return $this->hasMany(StudentRegistrationReplacementItem::class, 'student_registration_replacement_request_id')->orderBy('student_registration_replacement_item_id'); }
    public function events() { return $this->hasMany(StudentRegistrationReplacementEvent::class, 'student_registration_replacement_request_id')->orderBy('student_registration_replacement_event_id'); }
    public function advisor() { return $this->belongsTo(User::class, 'advisor_user_id', 'user_id'); }
}
