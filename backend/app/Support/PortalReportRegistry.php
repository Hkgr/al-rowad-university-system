<?php

namespace App\Support;

use App\Models\User;
use App\Services\DataScopeService;

/** Fixed read capabilities, never a permission grant or client-selected SQL identifier. */
final class PortalReportRegistry
{
    public const REPORTS = [
        'students' => ['حالات الطلاب الحالية', 'students.view', 'طلاب غير محذوفين؛ الحالة والبرنامج الحاليان، لا تاريخ تغيّر الحالة.', false],
        'offerings' => ['الطروحات الأكاديمية', 'courses.view', 'طرح فعلي واحد لكل صف؛ السنة والفصل من الطرح لا توصية المنهج.', true],
        'registrations' => ['التسجيلات الحالية', 'registration.view', 'صفوف registered فقط؛ العدد تسجيلات وليس عدد أشخاص مميزين.', true],
        'results' => ['النتائج الرسمية', 'grades.view', 'نتائج محاولات registered/completed التي أحدث اعتماد نهائي لها approved؛ لا علامات مكونات.', true],
        'parts' => ['تقدم إدخال أجزاء العلامات', 'exams.manage', 'جزء مطلوب مميز لكل طرح؛ غياب صف الاعتماد يعني مسودة وليس نتيجة رسمية.', true],
        'requests' => ['طلبات التسجيل الأولية', 'registration_requests.view', 'الطلبات بحالاتها المسجلة، لا تعني الحالة إرسالًا أو اعتمادًا جديدًا.', true],
        'progression' => ['قرارات الترفيع', 'academic_progression.view', 'قرارات الترفيع بحالاتها؛ المسودة ليست قرارًا نافذًا.', false],
        'graduation' => ['قرارات التخرج', 'graduation_decisions.view', 'قرارات التخرج بحالاتها؛ لا يعرض غير المعتمد على أنه خريج رسمي.', false],
        'admissions' => ['طلبات القبول', 'admissions.view', 'طلب قبول لكل برنامج وسنة؛ لا يساوي عدد الطلاب المنشئين.', false],
        'deprivation' => ['حالات الحرمان المسجلة', 'exams.manage', 'الحرمان المسجل على التسجيل الحالي؛ ليس إعادة احتساب للحضور أو نتيجة منشورة.', true],
        'sessions' => ['جلسات الحضور المسجلة', 'attendance.manage', 'جلسة فعلية لكل صف؛ لا تُعد الجلسات المجدولة أو المفقودة حضورًا.', true],
        'attendance' => ['سجل حضوري', 'attendance.view', 'قيود الحضور المسجلة للطالب؛ عدم وجود قيد ليس غيابًا مستنتجًا.', true],
        'programs' => ['البرامج الحالية', 'academic_structure.view', 'هويات البرامج الحالية مرة واحدة؛ لا تجمع نسخ الخطط التاريخية.', false],
        'employees' => ['توزيع الموظفين الحالي', 'hr.view', 'الموظفون حسب الحالة والوحدة الأساسية الحالية؛ ليس اتجاهًا تاريخيًا.', false],
        'faculty' => ['المدرسون الحاليون', 'teaching_staff.view', 'مدرس مميز واحد؛ الانتماء الحالي للوحدة الأساسية أو التكليف التنظيمي الفعال، لا تاريخ توظيف.', false],
        'supplementary' => ['تسجيلات التكميلي', 'supplementary_exams.registrations.view', 'تسجيلات التكميلي بحالتها، والفترة إن حددت تخص الطرح الأصلي المصدر؛ ليست نتائج منشورة.', true],
        'accounts' => ['حالات الحسابات', 'user_accounts.view', 'حساب واحد لكل صف وفق صلاحية المكتب التقني؛ لا كلمات مرور أو جهات اتصال.', false],
        'activity' => ['النشاط المسموح', 'system_activity.view', 'أحداث التدقيق ضمن وحدات النشاط المرئية؛ أكواد ووقت فقط دون وصف أو أسرار.', false],
        'academic' => ['كشفي وتقدمي الأكاديمي', 'grades.view', 'الكشف والتقدم الرسميان من الخدمات الحالية للحساب نفسه، دون إعادة حساب المعدل.', false],
        'trends' => ['اتجاهات الالتحاق والتخرج والنتائج', 'dashboards.view', 'سلاسل موثقة من بوابة الوزارة: الالتحاق حسب تاريخه، التخرج النافذ حسب الاعتماد، والنتائج الرسمية حسب الفصل الفعلي. ليست تاريخًا لتغير حالة الطالب.', false],
    ];

    public const PORTALS = [
        'dean' => ['students', 'faculty', 'offerings', 'registrations', 'results'],
        'student-affairs' => ['students', 'registrations', 'requests', 'progression', 'graduation', 'admissions'],
        'admissions' => ['admissions', 'registrations', 'requests'],
        'exam-board' => ['parts', 'results', 'deprivation', 'supplementary', 'offerings'],
        'professor' => ['offerings', 'registrations', 'attendance', 'sessions', 'parts'],
        'student' => ['academic', 'attendance', 'registrations'],
        'hr' => ['employees'], 'academic-structure' => ['programs'],
        'technical' => ['accounts', 'activity'],
        'president' => ['students', 'programs', 'faculty', 'offerings', 'results', 'trends'],
        'ministry' => ['students', 'programs', 'faculty', 'offerings', 'results', 'trends'],
    ];

    public function allows(User $user, string $portal, string $report): bool
    {
        if ($user->accountStatus?->status_code !== 'active' || ! in_array($report, self::PORTALS[$portal] ?? [], true)) {
            return false;
        }
        if ($portal === 'ministry') {
            return app(MinistryPortal::class)->allows($user, match ($report) {
                'students','results' => MinistryPortal::STUDENTS, 'programs' => MinistryPortal::COLLEGES, 'faculty' => MinistryPortal::FACULTY, 'offerings' => MinistryPortal::COURSES, default => MinistryPortal::DASHBOARD
            });
        }
        if ($user->effectiveRoles()->contains('ministry_observer')) {
            return false;
        }
        if ($portal === 'president') {
            return app(PresidentPortal::class)->allows($user, 'reports') && app(PresidentPortal::class)->allows($user, match ($report) {
                'students' => 'students', 'programs' => 'colleges', 'faculty' => 'staff', 'offerings','results' => 'exams', default => 'reports'
            });
        }
        $p = $user->effectivePermissions();
        $roles = $user->effectiveRoles();
        $permission = $portal === 'professor' ? match ($report) {
            'parts','offerings','registrations' => 'grades.manage', 'attendance' => 'attendance.manage', default => self::REPORTS[$report][1]
        } : self::REPORTS[$report][1];
        if (! $p->contains($permission)) {
            return false;
        }
        if ($portal === 'student') {
            return $user->student_id !== null && $roles->contains('student');
        }
        if ($portal === 'professor') {
            return $user->employee_id !== null && $roles->contains('doctor_instructor');
        }
        if ($portal === 'technical') {
            return $p->contains('technical_portal.access');
        }
        $scopes = collect(app(DataScopeService::class)->scopes($user));
        if ($portal === 'dean') {
            return $roles->contains('dean') && $scopes->contains('type', 'college');
        }
        if ($portal === 'hr') {
            return $scopes->contains(fn ($s) => in_array($s['type'], ['college', 'university'], true));
        }
        if ($portal === 'exam-board' && ! $roles->contains('exam_officer')) {
            return false;
        }
        if ($portal === 'admissions' && ! $roles->contains('registration_officer')) {
            return false;
        }

        return $scopes->contains(fn ($s) => in_array($s['type'], ['university', 'college', 'department', 'program'], true));
    }

    public function definitions(User $user, string $portal): array
    {
        abort_unless(isset(self::PORTALS[$portal]), 404);
        $items = [];
        foreach (self::PORTALS[$portal] as $id) {
            if ($this->allows($user, $portal, $id)) {
                [$title,$permission,$definition,$period] = self::REPORTS[$id];
                if ($portal === 'professor' && $id === 'attendance') {
                    $title = 'حضور طلاب طروحاتي';
                    $definition = 'قيود الحضور المسجلة في الطروحات المسندة للحساب فقط؛ غياب القيد ليس غيابًا مستنتجًا.';
                }
                $scoped = ! in_array($portal, ['student', 'professor', 'technical', 'hr']) && in_array($id, ['students', 'programs', 'offerings', 'results', 'parts', 'registrations', 'requests', 'progression', 'graduation', 'admissions', 'deprivation', 'supplementary', 'trends']);
                $items[] = compact('id', 'title', 'definition', 'period', 'scoped');
            }
        }
        abort_if($items === [], 403);

        return $items;
    }
}
