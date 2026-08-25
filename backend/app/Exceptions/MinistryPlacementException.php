<?php

namespace App\Exceptions;

use Exception;

class MinistryPlacementException extends Exception
{
    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 422,
        public readonly string $errorCode = 'ministry_placement_invalid',
    ) {
        parent::__construct($message);
    }

    public static function invalidWorkbook(array $errors): self
    {
        return new self(
            'يجب تصحيح أخطاء الملف قبل الاستيراد.',
            $errors,
            422,
            'ministry_placement_workbook_invalid',
        );
    }

    public static function recordLocked(): self
    {
        return new self(
            'انتقل السجل إلى مرحلة لاحقة ولا يمكن تعديل مطابقته.',
            [],
            409,
            'ministry_placement_record_locked',
        );
    }

    public static function groupStale(): self
    {
        return new self(
            'تغيرت سجلات المجموعة. حدّث البيانات قبل إعادة المحاولة.',
            [],
            409,
            'ministry_placement_group_stale',
        );
    }

    public static function groupNotBulkMatchable(): self
    {
        return new self(
            'لا يمكن تطبيق مطابقة جماعية على سجلات لا تحتوي رغبة وزارة محددة.',
            [],
            422,
            'ministry_placement_group_not_bulk_matchable',
        );
    }

    public static function programUnavailable(): self
    {
        return new self(
            'البرنامج الأكاديمي المحدد أو بنيته الأكاديمية غير نشطة.',
            ['academic_program_id' => ['ministry_placement_program_unavailable']],
            422,
            'ministry_placement_program_unavailable',
        );
    }
}
