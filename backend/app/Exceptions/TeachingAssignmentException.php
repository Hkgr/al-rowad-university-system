<?php

namespace App\Exceptions;

use Exception;

class TeachingAssignmentException extends Exception
{
    public const NOT_CURRENT = 'teaching_assignment_not_current';

    public const SUPERSEDED = 'teaching_assignment_superseded';

    public const ALREADY_EFFECTIVE = 'teaching_assignment_already_effective';

    public const INVALID_INSTRUCTOR = 'invalid_instructor';

    public const OFFERING_OUTSIDE_SCOPE = 'offering_outside_user_scope';

    public const SCIENTIFIC_REVIEW_FORBIDDEN = 'scientific_review_forbidden';

    public const ADMINISTRATIVE_REVIEW_FORBIDDEN = 'administrative_review_forbidden';

    public const RETURN_REASON_REQUIRED = 'return_reason_required';

    public const MATERIAL_CHANGE_REQUIRES_NEW_CYCLE = 'material_change_requires_new_cycle';

    public const DUPLICATE_CURRENT = 'teaching_assignment_duplicate_current';

    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 422,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    public static function notCurrent(): self
    {
        $message = 'طلب التكليف الحالي غير صالح لهذه العملية.';

        return new self($message, ['teaching_assignment' => [$message]], 409, self::NOT_CURRENT);
    }

    public static function superseded(): self
    {
        $message = 'لا يمكن مراجعة طلب تكليف مستبدل.';

        return new self($message, ['teaching_assignment' => [$message]], 409, self::SUPERSEDED);
    }

    public static function alreadyEffective(): self
    {
        $message = 'هذا التكليف معتمد ونافذ بالفعل.';

        return new self($message, ['teaching_assignment' => [$message]], 409, self::ALREADY_EFFECTIVE);
    }

    public static function invalidInstructor(): self
    {
        $message = 'المدرس المحدد غير صالح أو غير نشط.';

        return new self($message, ['faculty_member_id' => [$message]], 422, self::INVALID_INSTRUCTOR);
    }

    public static function offeringOutsideScope(): self
    {
        $message = 'ليس لديك صلاحية على طرح هذه المادة.';

        return new self($message, ['course_offering_id' => [$message]], 403, self::OFFERING_OUTSIDE_SCOPE);
    }

    public static function scientificReviewForbidden(): self
    {
        $message = 'ليست لديك صلاحية المراجعة العلمية.';

        return new self($message, ['teaching_assignment' => [$message]], 403, self::SCIENTIFIC_REVIEW_FORBIDDEN);
    }

    public static function administrativeReviewForbidden(): self
    {
        $message = 'ليست لديك صلاحية المراجعة الإدارية.';

        return new self($message, ['teaching_assignment' => [$message]], 403, self::ADMINISTRATIVE_REVIEW_FORBIDDEN);
    }

    public static function returnReasonRequired(): self
    {
        $message = 'سبب الإعادة للعميد مطلوب.';

        return new self($message, ['reason' => [$message]], 422, self::RETURN_REASON_REQUIRED);
    }

    public static function materialChangeRequiresNewCycle(): self
    {
        $message = 'تغيير المدرس أو الشق يبدأ دورة موافقة جديدة.';

        return new self($message, ['teaching_assignment' => [$message]], 422, self::MATERIAL_CHANGE_REQUIRES_NEW_CYCLE);
    }

    public static function duplicateCurrent(): self
    {
        $message = 'يوجد طلب تكليف حالي لنفس الطرح والشق.';

        return new self($message, ['teaching_assignment' => [$message]], 409, self::DUPLICATE_CURRENT);
    }
}
