<?php

namespace App\Exceptions;

use Exception;

class RegistrationException extends Exception
{
    public const LIVE_WORKFLOW_REQUIRED = 'registration_live_workflow_required';

    public const SELF_DROP_CLOSED = 'registration_self_drop_closed';

    public const NOT_CURRENT = 'registration_not_current';

    public const NOT_OWNED = 'registration_not_owned';

    public const WITHDRAWAL_REASON_REQUIRED = 'registration_withdrawal_reason_required';

    public const WITHDRAWAL_REQUIRES_CLOSED_OFFERING = 'registration_withdrawal_requires_closed_offering';

    public const WITHDRAWAL_ALREADY_CURRENT = 'registration_withdrawal_already_current';

    public const WITHDRAWAL_NOT_REQUIRED = 'registration_withdrawal_not_required';

    public const WITHDRAWAL_STALE = 'registration_withdrawal_stale';

    public const WITHDRAWAL_REVIEW_FORBIDDEN = 'registration_withdrawal_review_forbidden';

    public const WITHDRAWAL_RETURN_REASON_REQUIRED = 'registration_withdrawal_return_reason_required';

    public const WITHDRAWAL_ALREADY_MATERIALIZED = 'registration_withdrawal_already_materialized';

    public const WITHDRAWN_NOT_REACTIVATABLE = 'registration_withdrawn_not_reactivatable';

    public const GRADES_LOCKED = 'grades_locked';

    public const COURSE_REGISTRATION_WINDOW_CLOSED = 'course_registration_window_closed';

    public const ACADEMIC_CALENDAR_CONFIGURATION_INVALID = 'academic_calendar_configuration_invalid';

    public const ACADEMIC_CALENDAR_YEAR_CONTEXT_INVALID = 'academic_calendar_year_context_invalid';

    public const ACADEMIC_CALENDAR_SEMESTER_CONTEXT_INVALID = 'academic_calendar_semester_context_invalid';

    public const COURSE_ALREADY_PASSED = 'course_already_passed';

    public const TIMETABLE_SCHEMA_NOT_READY = 'timetable_schema_not_ready';

    public const OFFERING_SCHEDULE_INCOMPLETE = 'offering_schedule_incomplete';

    public const TIMETABLE_CONFLICT = 'timetable_conflict';

    public const TIMETABLE_REFERENCE_INCOMPLETE = 'timetable_reference_incomplete';

    public const REGISTRATION_MODIFICATION_REQUIRED = 'registration_modification_required';

    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 422,
        public readonly ?string $errorCode = null,
        public readonly array $data = [],
    ) {
        parent::__construct($message);
    }

    public static function liveWorkflowRequired(): self
    {
        $message = 'Live-semester registration must follow the student request and academic advisor workflow.';

        return new self($message, ['registration' => [$message]], 409, self::LIVE_WORKFLOW_REQUIRED);
    }

    public static function selfDropClosed(): self
    {
        $message = 'Self-drop is not allowed after the course offering has closed.';

        return new self($message, ['registration' => [$message]], 409, self::SELF_DROP_CLOSED);
    }

    public static function registrationModificationRequired(): self
    {
        $message = 'يتطلب إسقاط مقرر من تسجيل معتمد تقديم طلب تعديل التسجيل وموافقة المرشد الأكاديمي.';

        return new self($message, ['registration' => [$message]], 409, self::REGISTRATION_MODIFICATION_REQUIRED);
    }

    public static function notCurrent(): self
    {
        $message = 'This registration is not in a current registered state for the requested operation.';

        return new self($message, ['registration' => [$message]], 409, self::NOT_CURRENT);
    }

    public static function notOwned(): self
    {
        $message = 'You can only change your own course registration.';

        return new self($message, ['registration' => [$message]], 403, self::NOT_OWNED);
    }

    public static function withdrawalReasonRequired(): self
    {
        $message = 'A withdrawal reason is required.';

        return new self($message, ['request_reason' => [$message]], 422, self::WITHDRAWAL_REASON_REQUIRED);
    }

    public static function withdrawalRequiresClosedOffering(): self
    {
        $message = 'Withdrawal requires a closed course offering. Use drop while the offering is open.';

        return new self($message, ['registration' => [$message]], 409, self::WITHDRAWAL_REQUIRES_CLOSED_OFFERING);
    }

    public static function withdrawalAlreadyCurrent(): self
    {
        $message = 'A current withdrawal request already exists for this registration.';

        return new self($message, ['registration' => [$message]], 409, self::WITHDRAWAL_ALREADY_CURRENT);
    }

    public static function withdrawalNotRequired(): self
    {
        $message = 'A withdrawal request is not required for this registration.';

        return new self($message, ['registration' => [$message]], 409, self::WITHDRAWAL_NOT_REQUIRED);
    }

    public static function withdrawalStale(): self
    {
        $message = 'This withdrawal request is stale because the registration is no longer current.';

        return new self($message, ['registration' => [$message]], 409, self::WITHDRAWAL_STALE);
    }

    public static function withdrawalReviewForbidden(): self
    {
        $message = 'Only an academic advisor with assigned review permission may review withdrawal requests.';

        return new self($message, ['registration' => [$message]], 403, self::WITHDRAWAL_REVIEW_FORBIDDEN);
    }

    public static function withdrawalReturnReasonRequired(): self
    {
        $message = 'A return reason is required.';

        return new self($message, ['review_notes' => [$message]], 422, self::WITHDRAWAL_RETURN_REASON_REQUIRED);
    }

    public static function withdrawalAlreadyMaterialized(): self
    {
        $message = 'This withdrawal request has already been materialized.';

        return new self($message, ['registration' => [$message]], 409, self::WITHDRAWAL_ALREADY_MATERIALIZED);
    }

    public static function withdrawnNotReactivatable(): self
    {
        $message = 'A withdrawn registration cannot be reactivated in the same course offering.';

        return new self($message, ['course_offering_id' => [$message]], 409, self::WITHDRAWN_NOT_REACTIVATABLE);
    }

    public static function gradesLocked(): self
    {
        $message = 'Withdrawal is not allowed after grades for this offering have been submitted or locked.';

        return new self($message, ['registration' => [$message]], 409, self::GRADES_LOCKED);
    }

    public static function courseRegistrationWindowClosed(): self
    {
        $message = 'Course registration is not currently available according to the academic calendar.';

        return new self($message, ['registration' => [$message]], 409, self::COURSE_REGISTRATION_WINDOW_CLOSED);
    }

    public static function academicCalendarConfigurationInvalid(): self
    {
        $message = 'Course registration cannot continue because the academic calendar configuration is invalid.';

        return new self($message, ['registration' => [$message]], 409, self::ACADEMIC_CALENDAR_CONFIGURATION_INVALID);
    }

    public static function academicCalendarYearContextInvalid(): self
    {
        $message = 'The course offering academic year is not valid for live registration.';

        return new self($message, ['course_offering_id' => [$message]], 409, self::ACADEMIC_CALENDAR_YEAR_CONTEXT_INVALID);
    }

    public static function academicCalendarSemesterContextInvalid(): self
    {
        $message = 'The course offering semester is not valid for live registration.';

        return new self($message, ['course_offering_id' => [$message]], 409, self::ACADEMIC_CALENDAR_SEMESTER_CONTEXT_INVALID);
    }

    public static function courseAlreadyPassed(): self
    {
        $message = 'The student has already officially passed this course and cannot register it again in the normal workflow.';

        return new self(
            $message,
            ['course_offering_id' => [self::COURSE_ALREADY_PASSED]],
            422,
            self::COURSE_ALREADY_PASSED,
        );
    }

    public static function timetableSchemaNotReady(): self
    {
        $message = 'Course registration cannot continue because the official timetable schema is not ready.';

        return new self($message, ['timetable' => [self::TIMETABLE_SCHEMA_NOT_READY]], 503, self::TIMETABLE_SCHEMA_NOT_READY);
    }

    public static function offeringScheduleIncomplete(array $schedule): self
    {
        $message = 'The selected course official timetable is incomplete.';

        return new self(
            $message,
            ['course_offering_id' => [self::OFFERING_SCHEDULE_INCOMPLETE]],
            409,
            self::OFFERING_SCHEDULE_INCOMPLETE,
            [
                'components_defined' => $schedule['components_defined'] ?? false,
                'missing_schedule_components' => $schedule['missing_components'] ?? [],
            ],
        );
    }

    public static function timetableConflict(array $conflicts): self
    {
        $message = 'The selected course conflicts with the student official timetable.';

        return new self(
            $message,
            ['course_offering_id' => [self::TIMETABLE_CONFLICT]],
            409,
            self::TIMETABLE_CONFLICT,
            ['conflicts' => $conflicts],
        );
    }

    public static function timetableReferenceIncomplete(array $sources): self
    {
        $message = 'Timetable conflicts cannot be verified because a same-term registration timetable is incomplete.';

        return new self(
            $message,
            ['course_offering_id' => [self::TIMETABLE_REFERENCE_INCOMPLETE]],
            409,
            self::TIMETABLE_REFERENCE_INCOMPLETE,
            ['incomplete_timetable_sources' => $sources],
        );
    }
}
