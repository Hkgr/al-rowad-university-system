<?php

namespace App\Support;

/**
 * The two regular-exam occurrence domains exposed by the Academic Calendar.
 */
enum RegularExamPart: string
{
    case PRACTICAL = 'practical';
    case THEORETICAL = 'theoretical';

    public function calendarEventTypeCode(): string
    {
        return match ($this) {
            self::PRACTICAL => 'practical_exams',
            self::THEORETICAL => 'theoretical_exams',
        };
    }
}
