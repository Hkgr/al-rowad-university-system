<?php

namespace App\Services\Payroll;

/** The ten payroll grid columns, in display order (shared by the grid contract and both exports). */
final class PayrollColumns
{
    public const HEADINGS = [
        'employee_number' => 'رقم الموظف',
        'full_name' => 'الاسم الكامل',
        'job_title' => 'الصفة الوظيفية',
        'body' => 'الهيئة',
        'workplace' => 'مكان العمل',
        'academic_level' => 'المستوى الأكاديمي',
        'fixed_salary' => 'الراتب المقطوع $',
        'deduction' => 'الاقتطاع $',
        'compensation' => 'التعويض $',
        'payable' => 'المستحق $',
    ];

    public const SORT_LABELS = [
        'employee_number' => 'رقم الموظف', 'full_name' => 'الاسم الكامل', 'job_title' => 'الصفة الوظيفية', 'body' => 'الهيئة',
        'workplace' => 'مكان العمل', 'academic_level' => 'المستوى الأكاديمي', 'fixed_salary' => 'الراتب المقطوع',
        'deduction' => 'الاقتطاع', 'compensation' => 'التعويض', 'payable' => 'المستحق',
    ];

    public const TITLE = 'كشف الرواتب — ورقة العمل الحالية';

    public const CURRENT_SHEET_NOTE = 'ورقة عمل حالية وليست سجلًا شهريًا تاريخيًا.';

    /** Scope sentence shared by both files. */
    public static function scopeLine(array $snapshot, array $labels): string
    {
        $count = $snapshot['totals']['employees'];

        return $labels === []
            ? "النطاق: كل الصفوف دون مرشحات ({$count} موظفًا)"
            : "النطاق: الصفوف المطابقة للمرشحات فقط ({$count} موظفًا) — ".implode(' · ', $labels);
    }

    public static function sortLine(array $filters): string
    {
        return 'الترتيب: '.self::SORT_LABELS[$filters['sort']].($filters['direction'] === 'desc' ? ' (تنازلي)' : ' (تصاعدي)').' — الفراغ في آخر القائمة';
    }
}
