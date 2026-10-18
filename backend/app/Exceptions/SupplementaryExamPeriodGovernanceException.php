<?php

namespace App\Exceptions;

use Exception;

class SupplementaryExamPeriodGovernanceException extends Exception
{
    public const DECISION_FORBIDDEN = 'supplementary_exam_period_decision_forbidden';

    public const VIEW_FORBIDDEN = 'supplementary_exam_period_view_forbidden';

    public const IDENTITY_EXISTS = 'supplementary_exam_period_identity_exists';

    public const SCHEMA_NOT_READY = 'supplementary_exam_period_schema_not_ready';

    public const TRANSACTION_REQUIRED = 'supplementary_exam_period_transaction_required';

    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 422,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    public static function decisionForbidden(): self
    {
        $message = 'ليست لديك صلاحية اعتماد فتح دورة امتحانية تكميلية.';

        return new self($message, ['supplementary_exam_period' => [$message]], 403, self::DECISION_FORBIDDEN);
    }

    public static function viewForbidden(): self
    {
        $message = 'ليست لديك صلاحية عرض الدورات الامتحانية التكميلية.';

        return new self($message, ['supplementary_exam_period' => [$message]], 403, self::VIEW_FORBIDDEN);
    }

    public static function identityExists(): self
    {
        $message = 'توجد دورة امتحانية تكميلية مرتبطة بهذه السنة الأكاديمية وهذا الفصل. لا يمكن إنشاء دورة ثانية.';

        return new self($message, ['supplementary_exam_period' => [$message]], 409, self::IDENTITY_EXISTS);
    }

    public static function schemaNotReady(): self
    {
        $message = 'حوكمة الدورات الامتحانية التكميلية غير جاهزة على قاعدة البيانات.';

        return new self($message, ['supplementary_exam_period' => [$message]], 409, self::SCHEMA_NOT_READY);
    }

    public static function transactionRequired(): self
    {
        $message = 'اعتماد الدورة التكميلية لا يُنفَّذ إلا داخل معاملة مع قفل الهوية.';

        return new self($message, ['supplementary_exam_period' => [$message]], 409, self::TRANSACTION_REQUIRED);
    }
}
