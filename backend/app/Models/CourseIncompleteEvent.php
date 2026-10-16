<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class CourseIncompleteEvent extends Model
{
    protected $table = 'student_course_incomplete_events';
    protected $primaryKey = 'incomplete_event_id';
    public $timestamps = false;
    protected $guarded = ['*'];
    protected function casts(): array { return ['before_values' => 'array', 'after_values' => 'array', 'response_snapshot' => 'array', 'performed_at' => 'immutable_datetime']; }
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Incomplete events are append-only.'));
        static::deleting(fn () => throw new LogicException('Incomplete events are append-only.'));
    }
}
