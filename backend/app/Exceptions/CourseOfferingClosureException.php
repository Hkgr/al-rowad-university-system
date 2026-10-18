<?php

namespace App\Exceptions;

use Exception;

class CourseOfferingClosureException extends Exception
{
    public const FORBIDDEN = 'course_offering_closure_forbidden';

    public const REQUEST_FORBIDDEN = 'course_offering_closure_request_forbidden';

    public const SCIENTIFIC_REVIEW_FORBIDDEN = 'course_offering_closure_scientific_review_forbidden';

    public const ADMINISTRATIVE_REVIEW_FORBIDDEN = 'course_offering_closure_administrative_review_forbidden';

    public const SAME_REVIEWER_FORBIDDEN = 'course_offering_closure_same_reviewer_forbidden';

    public const NOT_REQUIRED = 'course_offering_closure_not_required';

    public const WORKFLOW_REQUIRED = 'course_offering_closure_workflow_required';

    public const REQUEST_ALREADY_CURRENT = 'course_offering_closure_request_already_current';

    public const REQUEST_STALE = 'course_offering_closure_request_stale';

    public const REVIEW_LOCKED = 'course_offering_closure_review_locked';

    public const RETURN_REASON_REQUIRED = 'course_offering_closure_return_reason_required';

    public const REASON_REQUIRED = 'course_offering_closure_reason_required';

    public const OFFERING_OUTSIDE_SCOPE = 'offering_outside_user_scope';

    public const NOT_CURRENT = 'course_offering_closure_not_current';

    public const SUPERSEDED = 'course_offering_closure_superseded';

    public const ALREADY_MATERIALIZED = 'course_offering_closure_already_materialized';

    public const TRANSACTION_REQUIRED = 'course_offering_closure_transaction_required';

    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 422,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    public static function forbidden(): self
    {
        $message = 'ليست لديك صلاحية مسار إغلاق طرح المادة.';

        return new self($message, ['course_offering_closure' => [$message]], 403, self::FORBIDDEN);
    }

    public static function requestForbidden(): self
    {
        $message = 'ليست لديك صلاحية طلب إغلاق طرح المادة.';

        return new self($message, ['course_offering_closure' => [$message]], 403, self::REQUEST_FORBIDDEN);
    }

    public static function scientificReviewForbidden(): self
    {
        $message = 'ليست لديك صلاحية المراجعة العلمية لإغلاق طرح المادة.';

        return new self($message, ['course_offering_closure' => [$message]], 403, self::SCIENTIFIC_REVIEW_FORBIDDEN);
    }

    public static function administrativeReviewForbidden(): self
    {
        $message = 'ليست لديك صلاحية المراجعة الإدارية لإغلاق طرح المادة.';

        return new self($message, ['course_offering_closure' => [$message]], 403, self::ADMINISTRATIVE_REVIEW_FORBIDDEN);
    }

    public static function sameReviewerForbidden(): self
    {
        $message = 'لا يجوز أن يوافق نفس المستخدم على المراجعة العلمية والمراجعة الإدارية لنفس طلب الإغلاق.';

        return new self($message, ['course_offering_closure' => [$message]], 409, self::SAME_REVIEWER_FORBIDDEN);
    }

    public static function notRequired(): self
    {
        $message = 'طرح المادة مغلق بالفعل. لا يلزم مسار الإغلاق الرسمي.';

        return new self($message, ['course_offering_closure' => [$message]], 409, self::NOT_REQUIRED);
    }

    public static function workflowRequired(): self
    {
        $message = 'لا يمكن إغلاق طرح مفتوح مباشرة. يجب إرسال طلب عبر مسار موافقة النائب العلمي والنائب الإداري.';

        return new self($message, ['course_offering_closure' => [$message]], 409, self::WORKFLOW_REQUIRED);
    }

    public static function requestAlreadyCurrent(): self
    {
        $message = 'يوجد طلب إغلاق حالي لنفس الطرح.';

        return new self($message, ['course_offering_closure' => [$message]], 409, self::REQUEST_ALREADY_CURRENT);
    }

    public static function requestStale(): self
    {
        $message = 'تغيرت هوية طرح المادة بعد إنشاء الطلب. لا يمكن إغلاق الطرح بهذا الطلب.';

        return new self($message, ['course_offering_closure' => [$message]], 409, self::REQUEST_STALE);
    }

    public static function reviewLocked(): self
    {
        $message = 'لا يمكن تغيير قرار المراجعة الحالي إلا بعد إعادة إرسال العميد.';

        return new self($message, ['course_offering_closure' => [$message]], 409, self::REVIEW_LOCKED);
    }

    public static function returnReasonRequired(): self
    {
        $message = 'سبب الإعادة للعميد مطلوب.';

        return new self($message, ['reason' => [$message]], 422, self::RETURN_REASON_REQUIRED);
    }

    public static function reasonRequired(): self
    {
        $message = 'سبب طلب إغلاق طرح المادة مطلوب.';

        return new self($message, ['reason' => [$message]], 422, self::REASON_REQUIRED);
    }

    public static function offeringOutsideScope(): self
    {
        $message = 'ليس لديك صلاحية على طرح هذه المادة.';

        return new self($message, ['course_offering_id' => [$message]], 403, self::OFFERING_OUTSIDE_SCOPE);
    }

    public static function notCurrent(): self
    {
        $message = 'طلب الإغلاق الحالي غير صالح لهذه العملية.';

        return new self($message, ['course_offering_closure' => [$message]], 409, self::NOT_CURRENT);
    }

    public static function superseded(): self
    {
        $message = 'لا يمكن مراجعة طلب إغلاق مستبدل.';

        return new self($message, ['course_offering_closure' => [$message]], 409, self::SUPERSEDED);
    }

    public static function alreadyMaterialized(): self
    {
        $message = 'هذا طلب الإغلاق استُهلك بالفعل ولا يمكن إعادة استخدامه.';

        return new self($message, ['course_offering_closure' => [$message]], 409, self::ALREADY_MATERIALIZED);
    }

    public static function transactionRequired(): self
    {
        $message = 'إغلاق طرح المادة لا يُنفَّذ إلا داخل معاملة مع قفل الصفوف.';

        return new self($message, ['course_offering_closure' => [$message]], 409, self::TRANSACTION_REQUIRED);
    }
}
