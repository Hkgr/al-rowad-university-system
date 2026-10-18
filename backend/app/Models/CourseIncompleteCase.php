<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Only IncompleteResultService writes this aggregate; no generic CRUD endpoint. */
class CourseIncompleteCase extends Model
{
    protected static function booted(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) static::{$event}(fn () => throw new \LogicException('Use the audited Incomplete lifecycle domain service.'));
    }
    protected $table = 'student_course_incomplete_cases';
    protected $primaryKey = 'incomplete_case_id';
    protected $guarded = ['*'];
    protected function casts(): array
    {
        return ['revision' => 'integer', 'granted_at' => 'immutable_datetime', 'deadline_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime', 'calendar_snapshot' => 'array', 'outcome_snapshot' => 'array'];
    }
    public function registration() { return $this->belongsTo(StudentCourseRegistration::class, 'student_course_registration_id'); }
    public function events() { return $this->hasMany(CourseIncompleteEvent::class, 'incomplete_case_id')->orderBy('revision'); }
}
