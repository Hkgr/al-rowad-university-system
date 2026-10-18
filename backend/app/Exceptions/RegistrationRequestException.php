<?php

namespace App\Exceptions;

use Exception;

class RegistrationRequestException extends Exception
{
    public const ADVISOR_DEADLINE_CLOSED = 'registration_request_advisor_deadline_closed';

    public const CALENDAR_CONFIGURATION_INVALID = 'registration_request_calendar_configuration_invalid';

    public const SUBMISSION_OUTSIDE_STUDENT_DEADLINE = 'registration_request_submission_outside_student_deadline';

    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 422,
        public readonly ?string $errorCode = null,
        public readonly array $itemFailures = [],
    ) {
        parent::__construct($message);
    }

    public static function advisorDeadlineClosed(): self
    {
        $message = 'انتهت مهلة اعتماد المرشد الأكاديمي دون اعتماد الطلب.';

        return new self($message, ['request' => [$message]], 409, self::ADVISOR_DEADLINE_CLOSED);
    }

    public static function calendarConfigurationInvalid(?string $reasonCode = null): self
    {
        $message = 'لا يمكن متابعة طلب التسجيل لأن إعداد فترة التسجيل الأكاديمية غير صالح.';

        return new self(
            $message,
            ['calendar' => [$reasonCode ?? 'invalid_registration_calendar']],
            409,
            self::CALENDAR_CONFIGURATION_INVALID,
        );
    }

    public static function submissionOutsideStudentDeadline(): self
    {
        $message = 'لا يمكن مراجعة الطلب لأن وقت إرساله لا يقع ضمن مهلة تسجيل الطلاب المعتمدة.';

        return new self(
            $message,
            ['last_submitted_at' => [$message]],
            409,
            self::SUBMISSION_OUTSIDE_STUDENT_DEADLINE,
        );
    }
}
