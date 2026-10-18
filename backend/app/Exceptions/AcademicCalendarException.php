<?php

namespace App\Exceptions;

use Exception;

class AcademicCalendarException extends Exception
{
    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 422,
        public readonly string $errorCode = 'academic_calendar_invalid',
    ) {
        parent::__construct($message);
    }

    public static function schemaNotReady(): self
    {
        return new self('بنية التقويم الأكاديمي غير جاهزة.', [], 503, 'academic_calendar_schema_not_ready');
    }

    public static function forbidden(): self
    {
        return new self('ليست لديك صلاحية إدارة التقويم الأكاديمي.', [], 403, 'academic_calendar_management_forbidden');
    }

    public static function conflict(string $message): self
    {
        return new self($message, [], 409, 'academic_calendar_conflict');
    }
}
