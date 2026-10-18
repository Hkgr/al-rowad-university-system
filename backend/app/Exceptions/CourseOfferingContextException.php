<?php

namespace App\Exceptions;

use Exception;

class CourseOfferingContextException extends Exception
{
    public const DUPLICATE_OFFERING = 'duplicate_offering';

    public const COURSE_NOT_IN_PROGRAM = 'course_not_in_program';

    public const PROGRAM_DEPARTMENT_MISMATCH = 'program_department_mismatch';

    public const PROGRAM_CONTEXT_INCOMPLETE = 'program_context_incomplete';

    public const PROGRAM_OUTSIDE_USER_SCOPE = 'program_outside_user_scope';

    public const OFFERING_IDENTITY_LOCKED = 'offering_identity_locked';

    public const CAPACITY_BELOW_OCCUPIED = 'course_offering_capacity_below_occupied';

    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 422,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    public static function duplicate(): self
    {
        $message = 'هذه المادة مفتوحة مسبقًا لهذا البرنامج في السنة والفصل المحددين.';

        return new self($message, ['course_offering' => [$message]], 422, self::DUPLICATE_OFFERING);
    }

    public static function courseNotInProgram(): self
    {
        $message = 'المادة ليست ضمن الخطة الأكاديمية لهذا البرنامج.';

        return new self($message, ['course_id' => [$message]], 422, self::COURSE_NOT_IN_PROGRAM);
    }

    public static function programDepartmentMismatch(): self
    {
        $message = 'بيانات البرنامج والقسم غير متطابقة.';

        return new self($message, ['department_id' => [$message]], 422, self::PROGRAM_DEPARTMENT_MISMATCH);
    }

    public static function programContextIncomplete(): self
    {
        $message = 'لا يمكن إنشاء طرح للمادة لأن البنية الأكاديمية للبرنامج غير مكتملة.';

        return new self($message, ['academic_program_id' => [$message]], 422, self::PROGRAM_CONTEXT_INCOMPLETE);
    }

    public static function programOutsideUserScope(): self
    {
        $message = 'ليس لديك صلاحية على هذا البرنامج.';

        return new self($message, ['academic_program_id' => [$message]], 403, self::PROGRAM_OUTSIDE_USER_SCOPE);
    }

    public static function identityLocked(): self
    {
        $message = 'لا يمكن تغيير هوية هذا الطرح لأنه مرتبط بتسجيلات أو حضور أو علامات أو تكليفات تدريسية.';

        return new self($message, ['course_offering' => [$message]], 422, self::OFFERING_IDENTITY_LOCKED);
    }

    public static function capacityBelowOccupied(): self
    {
        $message = 'Cannot reduce course offering capacity below currently registered students.';

        return new self($message, ['capacity' => [$message]], 409, self::CAPACITY_BELOW_OCCUPIED);
    }
}
