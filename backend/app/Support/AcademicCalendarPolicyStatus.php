<?php

namespace App\Support;

enum AcademicCalendarPolicyStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
    case INVALID_EVENT_TYPE = 'invalid_event_type';
    case INVALID_ACADEMIC_YEAR = 'invalid_academic_year';
    case INVALID_SEMESTER_CONTEXT = 'invalid_semester_context';
    case CALENDAR_CONFIGURATION_ERROR = 'calendar_configuration_error';
}
