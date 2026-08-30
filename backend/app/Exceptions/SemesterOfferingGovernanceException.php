<?php

namespace App\Exceptions;

use Exception;

class SemesterOfferingGovernanceException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 409,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    public static function schemaNotReady(): self
    {
        return new self('مخطط حوكمة الطروحات الفصلية غير جاهز.', 'semester_offering_schema_not_ready', 503);
    }

    public static function approvalRequired(): self
    {
        return new self('يجب تجهيز الطرح وإرساله واعتماده من نائب الرئيس العلمي قبل فتحه.', 'semester_offering_scientific_approval_required');
    }

    public static function curriculumUnavailable(): self
    {
        return new self('لا يمكن ربط الطرح بعضوية حالية وحيدة في منهاج البرنامج.', 'semester_offering_curriculum_context_invalid');
    }

    public static function invalidState(): self
    {
        return new self('تغيرت حالة طلب الطرح الفصلي. أعد تحميل البيانات وحاول مجددًا.', 'semester_offering_state_conflict');
    }

    public static function materialized(): self
    {
        return new self('تم استهلاك هذا الاعتماد في فتح سابق ولا يمكن استخدامه لإعادة الفتح.', 'semester_offering_approval_consumed');
    }

    public static function forbidden(): self
    {
        return new self('ليست لديك صلاحية تنفيذ هذه العملية على الطرح الفصلي.', 'semester_offering_forbidden', 403);
    }

    public static function returnReasonRequired(): self
    {
        return new self('سبب الإعادة للتعديل مطلوب.', 'semester_offering_return_reason_required', 422, [
            'reason' => ['سبب الإعادة للتعديل مطلوب.'],
        ]);
    }

    public static function minimumEnrollmentRequired(): self
    {
        return new self('الحد الأدنى للتسجيل مطلوب ويجب أن يكون عددًا صحيحًا موجبًا.', 'semester_offering_minimum_enrollment_required', 422, [
            'minimum_enrollment' => ['الحد الأدنى للتسجيل مطلوب ويجب أن يكون عددًا صحيحًا موجبًا.'],
        ]);
    }

    public static function mandatorySelectionRequired(): self
    {
        return new self('المقرر الإجباري مطلوب طرحه في الفصل النظامي ولا يمكن إلغاء تحديده.', 'semester_offering_mandatory_selection_required', 422);
    }
}
