<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseOfferingScheduleSlot extends Model
{
    protected $table = 'course_offering_schedule_slots';

    protected $primaryKey = 'course_offering_schedule_slot_id';

    protected $fillable = [
        'course_offering_id',
        'component_type',
        'day_of_week',
        'start_time',
        'end_time',
        'location_label',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id', 'course_offering_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'user_id');
    }
}
