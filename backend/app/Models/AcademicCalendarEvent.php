<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicCalendarEvent extends Model
{
    protected $table = 'academic_calendar_events';
    protected $primaryKey = 'academic_calendar_event_id';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id', 'academic_year_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'semester_id', 'semester_id');
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(AcademicCalendarEventType::class, 'academic_calendar_event_type_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AcademicCalendarEventVersion::class, 'academic_calendar_event_id');
    }
}
