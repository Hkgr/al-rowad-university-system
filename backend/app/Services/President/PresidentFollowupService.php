<?php

namespace App\Services\President;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Audited workflow inventory, NOT a new escalation or decision engine. No deadlines inferred. */
final class PresidentFollowupService
{
    public const WORKFLOWS = [
        'teaching' => ['تكليفات التدريس', 'teaching_assignment_requests', 'teaching_assignment_request_id', 'status', 'العميد ثم النائبان العلمي والإداري', 'TeachingAssignmentWorkflowService'],
        'opening' => ['تجهيز الطروحات', 'semester_offering_requests', 'semester_offering_request_id', 'status', 'العميد ثم النائب العلمي', 'SemesterOfferingGovernanceService'],
        'closure' => ['إغلاق الطروحات', 'course_offering_closure_requests', 'course_offering_closure_request_id', 'status', 'العميد ثم النائبان العلمي والإداري', 'CourseOfferingClosureWorkflowService'],
        'grades' => ['أجزاء العلامات', 'grade_part_approvals', 'grade_part_approval_id', 'status', 'هيئة الامتحانات وفق صلاحية مراجعة الأجزاء', 'GradePartWorkflowService'],
        'registration' => ['طلبات التسجيل', 'student_registration_requests', 'student_registration_request_id', 'status', 'الطالب ثم المرشد الأكاديمي', 'RegistrationRequestService'],
        'modifications' => ['تعديلات التسجيل المعتمد', 'student_registration_modification_requests', 'student_registration_modification_request_id', 'status', 'الطالب ثم المرشد الأكاديمي', 'RegistrationModificationService'],
        'replacements' => ['استبدال المقررات الملغاة', 'student_registration_replacement_requests', 'student_registration_replacement_request_id', 'status', 'الطالب ثم المرشد الأكاديمي', 'RegistrationReplacementService'],
        'withdrawal' => ['الانسحاب من مقرر مغلق', 'student_registration_withdrawal_requests', 'student_registration_withdrawal_request_id', 'status', 'الطالب ثم المرشد الأكاديمي', 'RegistrationWithdrawalService'],
        'exceptional-opening' => ['الفتح الاستثنائي', 'course_offering_exception_requests', 'course_offering_exception_request_id', 'status', 'العميد ثم النائبان العلمي والإداري', 'CourseOfferingExceptionWorkflowService'],
        'minimum' => ['الحد الأدنى للتسجيل', 'course_offering_minimum_enrollment_reviews', 'course_offering_minimum_enrollment_review_id', 'status', 'توصية العميد ثم العلمي؛ الإلغاء عبر الإغلاق الثنائي', 'MinimumEnrollmentReviewService'],
        'plans' => ['إصدارات الخطط', 'academic_plan_versions', 'academic_plan_version_id', 'status', 'النائب العلمي وفق صلاحية اعتماد الخطة', 'AcademicPlanWorkflow'],
        'calendar' => ['نسخ أحداث التقويم', 'academic_calendar_event_versions', 'academic_calendar_event_version_id', 'publication_status', 'النائب العلمي وفق academic_calendar.manage', 'AcademicCalendarService'],
        'supplementary-periods' => ['دورات التكميلي', 'supplementary_exam_periods', 'supplementary_exam_period_id', 'status', 'النائب العلمي وفق supplementary_exams.periods.decide', 'SupplementaryExamPeriodGovernanceService'],
        'supplementary-grades' => ['علامات التكميلي', 'supplementary_exam_grade_submissions', 'supplementary_exam_grade_submission_id', 'status', 'الأستاذ وهيئة الامتحانات وفق صلاحيات التكميلي الحالية', 'SupplementaryExamGradingService'],
        'progression' => ['الترفيع', 'student_progression_decisions', 'student_progression_decision_id', 'status', 'مسجل شؤون الطلاب وفق academic_progression.review', 'AcademicProgressionService'],
        'graduation' => ['التخرج', 'student_graduation_decisions', 'student_graduation_decision_id', 'status', 'مسجل شؤون الطلاب وفق graduation_decisions.review', 'GraduationDecisionService'],
        'discipline' => ['القرارات التأديبية المسجلة', 'student_disciplinary_cases', 'case_id', 'case_status', 'جهة القرار محفوظة في السجل؛ لا توجد إحالة إلكترونية للرئيس', 'DisciplinaryCaseService'],
    ];

    public function definitions(): array
    {
        return collect(self::WORKFLOWS)->map(function ($d, $key) {
            $ready = Schema::hasColumns($d[1], [$d[2], $d[3]]);
            $states = $ready ? DB::table($d[1])->groupBy($d[3])->orderBy($d[3])->selectRaw($d[3].' as status, COUNT(*) as total')->get() : [];
            return ['id' => $key, 'name' => $d[0], 'owner' => $d[4], 'source' => $d[5], 'states' => $states,
                'available' => $ready, 'reason' => $ready ? null : 'مخطط المسار غير متاح.', 'president_action' => false,
                'escalation' => 'لا توجد إحالة للرئيس مثبتة في هذا المسار؛ العرض للمتابعة فقط.'];
        })->values()->all();
    }

    public function inbox(): array
    {
        return ['available' => false, 'items' => [], 'reason' => 'لا يوجد في المسارات المدققة صندوق إحالات إلكترونية أو إجراء اعتماد مخوّل لدور الرئيس. راجع سجل المسارات للمتابعة فقط؛ لا يعني ذلك خلو الجامعة من أعمال تحتاج متابعة.'];
    }

    public function records(string $source, array $f, ?int $id = null): array
    {
        abort_unless(isset(self::WORKFLOWS[$source]), 404);
        $d = self::WORKFLOWS[$source];
        if (! Schema::hasColumns($d[1], [$d[2], $d[3]])) return ['data' => [], 'available' => false, 'reason' => 'مخطط المسار غير متاح.', 'meta' => ['total' => 0]];
        $q = DB::table($d[1]);
        if ($id !== null) $q->where($d[2], $id);
        if (! empty($f['status'])) $q->where($d[3], $f['status']);
        $total = (clone $q)->count();
        $page = $f['page'] ?? 1; $size = $f['per_page'] ?? 25;
        // IDs/statuses only. No investigation, reviewer notes, private evidence or actor accounts.
        $columns = [$d[2].' as id', $d[3].' as status'];
        if ($source === 'discipline' && Schema::hasColumns($d[1], ['decided_by_authority', 'decision_date'])) $columns = array_merge($columns, ['decided_by_authority', 'decision_date']);
        $rows = $q->orderByDesc($d[2])->forPage($page, $size)->get($columns);
        if ($id !== null) abort_if($rows->isEmpty(), 404);
        return ['data' => $rows, 'definition' => ['name' => $d[0], 'owner' => $d[4], 'source' => $d[5]], 'available' => true,
            'president_action' => false, 'meta' => ['total' => $total, 'current_page' => $page, 'per_page' => $size, 'last_page' => max(1, (int) ceil($total / $size))]];
    }
}
