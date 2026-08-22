<?php

namespace App\Exceptions;

use Exception;

class SupplementaryExamOfferingException extends Exception
{
    public const SCHEMA_NOT_READY = 'supplementary_exam_offering_schema_not_ready';

    public const PERIOD_NOT_MANAGEABLE = 'supplementary_exam_period_not_manageable';

    public const UNSUPPORTED_SEMESTER_POLICY = 'supplementary_exam_unsupported_semester_policy';

    public const PROGRAM_OUT_OF_SCOPE = 'supplementary_exam_program_out_of_scope';

    public const NO_ACTUAL_SOURCE_OFFERING = 'supplementary_exam_no_actual_source_offering';

    public const OFFERING_EXISTS = 'supplementary_exam_offering_exists';

    public const OFFERING_NOT_OPEN = 'supplementary_exam_offering_not_open';

    public const OFFERING_NOT_CLOSED = 'supplementary_exam_offering_not_closed';

    public const SOURCE_STALE = 'supplementary_exam_source_stale';

    public const MANAGE_FORBIDDEN = 'supplementary_exam_offering_manage_forbidden';

    public const VIEW_FORBIDDEN = 'supplementary_exam_offering_view_forbidden';

    public const TRANSACTION_REQUIRED = 'supplementary_exam_offering_transaction_required';

    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 422,
        public readonly ?string $errorCode = null,
        public readonly array $data = [],
    ) {
        parent::__construct($message);
    }

    public static function schemaNotReady(): self
    {
        $message = 'حوكمة طرح مواد الامتحانات التكميلية غير جاهزة على قاعدة البيانات.';

        return new self($message, ['supplementary_exam_offering' => [$message]], 409, self::SCHEMA_NOT_READY);
    }

    public static function periodNotManageable(): self
    {
        $message = 'لا يمكن إدارة طرح المواد إلا لدورة تكميلية معلنة وغير تراثية.';

        return new self($message, ['supplementary_exam_period' => [$message]], 409, self::PERIOD_NOT_MANAGEABLE);
    }

    public static function unsupportedSemesterPolicy(): self
    {
        $message = 'ترتيب الفصل الدراسي لهذه الدورة التكميلية غير مدعوم في سياسة المصادر.';

        return new self($message, ['supplementary_exam_period' => [$message]], 422, self::UNSUPPORTED_SEMESTER_POLICY);
    }

    public static function programOutOfScope(): self
    {
        $message = 'هذا البرنامج خارج نطاق صلاحية العميد.';

        return new self($message, ['academic_program_id' => [$message]], 403, self::PROGRAM_OUT_OF_SCOPE);
    }

    public static function noActualSourceOffering(): self
    {
        $message = 'لا توجد مواد مستوفية لشروط الطرح التكميلي لهذا البرنامج ضمن نطاق الدورة المحددة.';

        return new self($message, ['course_id' => [$message]], 422, self::NO_ACTUAL_SOURCE_OFFERING);
    }

    public static function offeringExists(array $current = []): self
    {
        $message = 'هذه المادة مطروحة مسبقًا في هذه الدورة التكميلية لهذا البرنامج. استخدم إعادة الفتح إذا كانت مغلقة.';

        return new self($message, ['supplementary_exam_offering' => [$message]], 409, self::OFFERING_EXISTS, $current);
    }

    public static function offeringNotOpen(): self
    {
        $message = 'لا يمكن إغلاق طرح تكميلي غير مفتوح.';

        return new self($message, ['supplementary_exam_offering' => [$message]], 409, self::OFFERING_NOT_OPEN);
    }

    public static function offeringNotClosed(): self
    {
        $message = 'لا يمكن إعادة فتح طرح تكميلي غير مغلق.';

        return new self($message, ['supplementary_exam_offering' => [$message]], 409, self::OFFERING_NOT_CLOSED);
    }

    public static function sourceStale(): self
    {
        $message = 'لم تعد مصادر الطرح التكميلي مستوفية لشروط الإثبات الأكاديمي. لا يمكن إعادة الفتح.';

        return new self($message, ['supplementary_exam_offering' => [$message]], 409, self::SOURCE_STALE);
    }

    public static function manageForbidden(): self
    {
        $message = 'ليست لديك صلاحية إدارة طرح مواد الامتحانات التكميلية.';

        return new self($message, ['supplementary_exam_offering' => [$message]], 403, self::MANAGE_FORBIDDEN);
    }

    public static function viewForbidden(): self
    {
        $message = 'ليست لديك صلاحية عرض طرح مواد الامتحانات التكميلية.';

        return new self($message, ['supplementary_exam_offering' => [$message]], 403, self::VIEW_FORBIDDEN);
    }

    public static function transactionRequired(): self
    {
        $message = 'تعديل الطرح التكميلي لا يُنفَّذ إلا داخل معاملة.';

        return new self($message, ['supplementary_exam_offering' => [$message]], 409, self::TRANSACTION_REQUIRED);
    }
}
