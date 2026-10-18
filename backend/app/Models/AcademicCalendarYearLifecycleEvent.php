<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicCalendarYearLifecycleEvent extends Model
{
    protected $table = 'academic_calendar_year_lifecycle_events';
    protected $primaryKey = 'academic_calendar_year_lifecycle_event_id';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id', 'academic_year_id');
    }
}
