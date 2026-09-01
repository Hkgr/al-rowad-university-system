<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

final class SemesterRegistrationPhase6
{
    public const REPLACEMENT_EVENT_TYPE = 'course_registration_replacement';
    public const MINIMUM_STATUSES = ['satisfied', 'under_minimum', 'dean_recommended', 'continued_exceptionally', 'closure_pending', 'cancelled', 'superseded'];
    public const REPLACEMENT_STATUSES = ['draft', 'submitted', 'returned', 'approved', 'expired', 'superseded'];
    public const TERMINAL_MINIMUM_STATUSES = ['satisfied', 'continued_exceptionally', 'cancelled', 'superseded'];
    public const TERMINAL_REPLACEMENT_STATUSES = ['approved', 'expired', 'superseded'];
    public const EVENT_REPLACEMENT_SOURCE_CHANGED = 'superseded_source_changed';
    public const EVENT_REPLACEMENT_CALENDAR_CHANGED = 'superseded_calendar_event_changed';

    public static function schemaReady(): bool
    {
        foreach (['course_offering_minimum_enrollment_reviews', 'course_offering_minimum_enrollment_events', 'student_registration_replacement_requests', 'student_registration_replacement_items', 'student_registration_replacement_events'] as $table) {
            if (! Schema::hasTable($table)) return false;
        }
        return Schema::hasColumn('student_registration_replacement_items', 'source_consumed_slot');
    }
}
