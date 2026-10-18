<?php

namespace App\Support;

enum CourseRegistrationPhase: string
{
    case NOT_STARTED = 'not_started';
    case STUDENT_OPEN = 'student_open';
    case ADVISOR_REVIEW = 'advisor_review';
    case CLOSED = 'closed';
    case CONFIGURATION_ERROR = 'configuration_error';
}
