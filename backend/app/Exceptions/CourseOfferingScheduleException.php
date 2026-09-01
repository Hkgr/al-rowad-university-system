<?php

namespace App\Exceptions;

use Exception;

class CourseOfferingScheduleException extends Exception
{
    public const SCHEMA_NOT_READY = 'timetable_schema_not_ready';

    public const CALENDAR_SCHEMA_NOT_READY = 'registration_calendar_schema_not_ready';

    public const LOCKED = 'offering_schedule_locked';

    public const INCOMPLETE = 'offering_schedule_incomplete';

    public const CONFLICT = 'timetable_conflict';

    public const REFERENCE_INCOMPLETE = 'timetable_reference_incomplete';

    public const INVALID_COMPONENT = 'offering_schedule_component_not_required';

    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly array $errors = [],
        public readonly array $data = [],
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }

    public static function schemaNotReady(): self
    {
        return new self(
            'The official course timetable schema is not ready.',
            self::SCHEMA_NOT_READY,
            ['timetable' => [self::SCHEMA_NOT_READY]],
            status: 503,
        );
    }

    public static function calendarSchemaNotReady(): self
    {
        return new self(
            'The registration calendar schema is not ready, so timetable mutability cannot be proven.',
            self::CALENDAR_SCHEMA_NOT_READY,
            ['timetable' => [self::CALENDAR_SCHEMA_NOT_READY]],
            status: 503,
        );
    }

    public static function locked(string $reason): self
    {
        return new self(
            'The official course timetable can no longer be changed.',
            self::LOCKED,
            ['timetable' => [$reason]],
            ['locked_reason' => $reason],
        );
    }

    public static function invalidComponent(string $component): self
    {
        return new self(
            'The timetable contains a component that is not required by this course.',
            self::INVALID_COMPONENT,
            ['slots' => [$component]],
            ['component_type' => $component],
            422,
        );
    }

    public static function incomplete(array $description): self
    {
        return new self(
            'The official course timetable is incomplete.',
            self::INCOMPLETE,
            ['timetable' => [self::INCOMPLETE]],
            [
                'components_defined' => $description['components_defined'] ?? false,
                'missing_schedule_components' => $description['missing_components'] ?? [],
            ],
        );
    }

    public static function conflict(array $conflicts): self
    {
        return new self(
            'The selected course conflicts with the student timetable.',
            self::CONFLICT,
            ['timetable' => [self::CONFLICT]],
            ['conflicts' => $conflicts],
        );
    }
}
