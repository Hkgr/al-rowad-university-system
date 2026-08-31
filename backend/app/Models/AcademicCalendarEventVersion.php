<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicCalendarEventVersion extends Model
{
    protected $table = 'academic_calendar_event_versions';
    protected $primaryKey = 'academic_calendar_event_version_id';
    public $timestamps = false;
    protected $guarded = ['published_event_slot'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime',
            'student_registration_ends_at' => 'datetime', 'advisor_approval_ends_at' => 'datetime',
            'is_enforcement' => 'boolean',
            'created_at' => 'datetime', 'published_at' => 'datetime', 'superseded_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(AcademicCalendarEvent::class, 'academic_calendar_event_id');
    }

    public function replacesVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_version_id');
    }
}
