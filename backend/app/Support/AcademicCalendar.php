<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

final class AcademicCalendar
{
    public const PERMISSION_MANAGE = 'academic_calendar.manage';

    public const INITIAL_CHANGE_REASON = 'إنشاء المسودة الأولية للحدث.';

    public static function schemaReady(): bool
    {
        foreach ([
            'academic_years',
            'semesters',
            'academic_calendar_event_types',
            'academic_calendar_events',
            'academic_calendar_event_versions',
            'academic_calendar_year_lifecycle_events',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return Schema::hasColumns('academic_years', ['calendar_lifecycle_status', 'calendar_active_slot'])
            && self::registrationDeadlineSchemaReady();
    }

    public static function registrationDeadlineSchemaReady(): bool
    {
        return Schema::hasTable('academic_calendar_event_versions')
            && Schema::hasTable('student_registration_requests')
            && Schema::hasColumns('academic_calendar_event_versions', [
                'student_registration_ends_at',
                'advisor_approval_ends_at',
            ])
            && Schema::hasColumn('student_registration_requests', 'expired_at');
    }
}
