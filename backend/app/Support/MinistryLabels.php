<?php

namespace App\Support;

/** Arabic labels for codes shown in the ministry portal (display only, never used for filtering). */
final class MinistryLabels
{
    public const STUDENT_STATUS = [
        'active' => 'نشط', 'frozen' => 'مجمّد القيد', 'graduated' => 'متخرج', 'withdrawn' => 'منسحب',
        'dismissed' => 'مفصول', 'suspended' => 'موقوف', 'deceased' => 'متوفى',
    ];

    public const RESULT_STATUS = [
        'passed' => 'ناجح', 'failed' => 'راسب', 'deprived' => 'محروم', 'incomplete' => 'غير مكتمل', 'pending_approval' => 'بانتظار الاعتماد',
    ];

    public const SEMESTER = ['first' => 'الفصل الأول', 'second' => 'الفصل الثاني', 'summer' => 'الفصل الصيفي'];

    public const LEVEL_ORDINALS = [1 => 'السنة الأولى', 2 => 'السنة الثانية', 3 => 'السنة الثالثة', 4 => 'السنة الرابعة', 5 => 'السنة الخامسة', 6 => 'السنة السادسة'];

    public const EMPLOYEE_STATUS = ['active' => 'على رأس العمل', 'inactive' => 'غير نشط', 'on_leave' => 'في إجازة', 'terminated' => 'منتهية خدمته'];

    public const OFFERING_STATUS = ['open' => 'مفتوح', 'closed' => 'مغلق'];

    public const PLAN_STATUS = ['draft' => 'مسودة', 'approved' => 'معتمدة', 'transitional' => 'انتقالية', 'archived' => 'مؤرشفة'];

    public const COURSE_TYPE = ['required' => 'إجباري', 'elective' => 'اختياري', 'university_requirement' => 'متطلب جامعة', 'college_requirement' => 'متطلب كلية'];

    public const INSTRUCTOR_ROLE = ['theoretical' => 'نظري', 'practical' => 'عملي'];

    public const POSITION = [
        'PRESIDENT' => 'رئيس الجامعة', 'VICE_PRESIDENT' => 'نائب رئيس الجامعة', 'SECRETARY_GENERAL' => 'الأمين العام للجامعة',
        'DEAN' => 'عميد', 'HEAD_DEPARTMENT' => 'رئيس قسم', 'INSTRUCTOR' => 'عضو هيئة تدريسية', 'ACADEMIC_ADVISOR' => 'مرشد أكاديمي',
        'REGISTRATION_OFFICER' => 'موظف تسجيل', 'EXAM_OFFICER' => 'موظف امتحانات', 'HR_OFFICER' => 'موظف موارد بشرية', 'LIBRARIAN' => 'أمين مكتبة',
    ];

    public const UNIT_TYPE = [
        'board' => 'مجلس', 'council' => 'مجلس', 'presidency' => 'رئاسة', 'vice_presidency' => 'نيابة رئاسة', 'administration' => 'إدارة',
        'directorate' => 'مديرية', 'office' => 'مكتب', 'center' => 'مركز', 'club' => 'نادٍ', 'college' => 'كلية', 'department' => 'قسم',
        'institute' => 'معهد', 'lab' => 'مخبر', 'committee' => 'لجنة', 'unit' => 'وحدة',
    ];

    public static function studentStatus(?string $code, ?string $fallback = null): string
    {
        return self::STUDENT_STATUS[$code] ?? ($fallback ?: ($code ?: 'غير محدد'));
    }

    public static function level(?int $order, ?string $fallback = null): string
    {
        return self::LEVEL_ORDINALS[$order] ?? ($fallback ?: 'غير محدد');
    }

    public static function semester(?string $code, ?string $fallback = null): string
    {
        return self::SEMESTER[$code] ?? ($fallback ?: ($code ?: 'غير محدد'));
    }

    public static function of(array $map, ?string $code, string $unknown = 'غير محدد'): string
    {
        return $map[$code] ?? ($code ?: $unknown);
    }
}
