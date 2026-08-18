<?php

namespace App\Exceptions;

use Exception;

class ExceptionalOpeningException extends Exception
{
    public const NOT_REQUIRED = 'exceptional_opening_not_required';

    public const REQUEST_STALE = 'exceptional_opening_request_stale';

    public const NORMAL_OPENING_AVAILABLE = 'normal_opening_available';

    public const NOT_CURRENT = 'exceptional_opening_not_current';

    public const SUPERSEDED = 'exceptional_opening_superseded';

    public const SUPERSEDED_ALREADY_OPEN = 'exceptional_opening_superseded_already_open';

    public const ALREADY_MATERIALIZED = 'exceptional_opening_already_materialized';

    public const DUPLICATE_CURRENT = 'exceptional_opening_duplicate_current';

    public const OFFERING_NOT_CLOSED = 'exceptional_opening_offering_not_closed';

    public const OFFERING_OUTSIDE_SCOPE = 'offering_outside_user_scope';

    public const SCIENTIFIC_REVIEW_FORBIDDEN = 'scientific_review_forbidden';

    public const ADMINISTRATIVE_REVIEW_FORBIDDEN = 'administrative_review_forbidden';

    public const RETURN_REASON_REQUIRED = 'return_reason_required';

    public const REASON_REQUIRED = 'exceptional_opening_reason_required';

    public const REVIEW_LOCKED = 'exceptional_opening_review_locked';

    public const REQUEST_FORBIDDEN = 'exceptional_opening_request_forbidden';

    public const TRANSACTION_REQUIRED = 'exceptional_opening_transaction_required';

    public const PROOF_INVALID = 'exceptional_opening_proof_invalid';

    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 422,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    public static function notRequired(): self
    {
        $message = 'تكليف المدرسين مكتمل. استخدم الفتح الاعتيادي.';

        return new self($message, ['exceptional_opening' => [$message]], 409, self::NOT_REQUIRED);
    }

    public static function requestStale(): self
    {
        $message = 'تغيرت هوية طرح المادة بعد إنشاء الطلب. لا يمكن فتح الطرح بهذا الطلب.';

        return new self($message, ['exceptional_opening' => [$message]], 409, self::REQUEST_STALE);
    }

    public static function normalOpeningAvailable(): self
    {
        $message = 'أصبح الفتح الاعتيادي متاحًا لاكتمال تكليف المدرسين. لم يُستخدم الطلب الاستثنائي.';

        return new self($message, ['exceptional_opening' => [$message]], 409, self::NORMAL_OPENING_AVAILABLE);
    }

    public static function notCurrent(): self
    {
        $message = 'طلب الفتح الاستثنائي الحالي غير صالح لهذه العملية.';

        return new self($message, ['exceptional_opening' => [$message]], 409, self::NOT_CURRENT);
    }

    public static function superseded(): self
    {
        $message = 'لا يمكن مراجعة طلب فتح استثنائي مستبدل.';

        return new self($message, ['exceptional_opening' => [$message]], 409, self::SUPERSEDED);
    }

    public static function supersededAlreadyOpen(): self
    {
        $message = 'الطرح مفتوح بالفعل. أُلغي الطلب الاستثنائي ولم يعد صالحًا لإعادة الفتح.';

        return new self($message, ['exceptional_opening' => [$message]], 409, self::SUPERSEDED_ALREADY_OPEN);
    }

    public static function alreadyMaterialized(): self
    {
        $message = 'هذا الطلب الاستثنائي استُهلك بالفعل ولا يمكن إعادة استخدامه.';

        return new self($message, ['exceptional_opening' => [$message]], 409, self::ALREADY_MATERIALIZED);
    }

    public static function duplicateCurrent(): self
    {
        $message = 'يوجد طلب فتح استثنائي حالي لنفس الطرح.';

        return new self($message, ['exceptional_opening' => [$message]], 409, self::DUPLICATE_CURRENT);
    }

    public static function offeringNotClosed(): self
    {
        $message = 'لا يمكن طلب الفتح الاستثنائي إلا لطرح مغلق.';

        return new self($message, ['exceptional_opening' => [$message]], 409, self::OFFERING_NOT_CLOSED);
    }

    public static function offeringOutsideScope(): self
    {
        $message = 'ليس لديك صلاحية على طرح هذه المادة.';

        return new self($message, ['course_offering_id' => [$message]], 403, self::OFFERING_OUTSIDE_SCOPE);
    }

    public static function scientificReviewForbidden(): self
    {
        $message = 'ليست لديك صلاحية المراجعة العلمية للفتح الاستثنائي.';

        return new self($message, ['exceptional_opening' => [$message]], 403, self::SCIENTIFIC_REVIEW_FORBIDDEN);
    }

    public static function administrativeReviewForbidden(): self
    {
        $message = 'ليست لديك صلاحية المراجعة الإدارية للفتح الاستثنائي.';

        return new self($message, ['exceptional_opening' => [$message]], 403, self::ADMINISTRATIVE_REVIEW_FORBIDDEN);
    }

    public static function returnReasonRequired(): self
    {
        $message = 'سبب الإعادة للعميد مطلوب.';

        return new self($message, ['reason' => [$message]], 422, self::RETURN_REASON_REQUIRED);
    }

    public static function reasonRequired(): self
    {
        $message = 'سبب طلب الفتح الاستثنائي مطلوب.';

        return new self($message, ['reason' => [$message]], 422, self::REASON_REQUIRED);
    }

    public static function reviewLocked(): self
    {
        $message = 'لا يمكن تغيير قرار المراجعة الحالي إلا بعد إعادة إرسال العميد.';

        return new self($message, ['exceptional_opening' => [$message]], 409, self::REVIEW_LOCKED);
    }

    public static function requestForbidden(): self
    {
        $message = 'ليست لديك صلاحية طلب الفتح الاستثنائي.';

        return new self($message, ['exceptional_opening' => [$message]], 403, self::REQUEST_FORBIDDEN);
    }

    public static function transactionRequired(): self
    {
        $message = 'الفتح الاستثنائي لا يُنفَّذ إلا داخل معاملة مع قفل الصفوف.';

        return new self($message, ['exceptional_opening' => [$message]], 409, self::TRANSACTION_REQUIRED);
    }

    public static function proofInvalid(): self
    {
        $message = 'إثبات طلب الفتح الاستثنائي غير صالح.';

        return new self($message, ['exceptional_opening' => [$message]], 409, self::PROOF_INVALID);
    }
}
