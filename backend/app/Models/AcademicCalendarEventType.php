<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicCalendarEventType extends Model
{
    protected $table = 'academic_calendar_event_types';
    protected $primaryKey = 'academic_calendar_event_type_id';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['default_is_enforcement' => 'boolean', 'is_active' => 'boolean'];
    }

    public function events(): HasMany
    {
        return $this->hasMany(AcademicCalendarEvent::class, 'academic_calendar_event_type_id');
    }
}
