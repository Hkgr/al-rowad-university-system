<?php

namespace Tests\Feature;

use Tests\TestCase;

class SupplementaryExamPhase7FrontendContractTest extends TestCase
{
    private function frontend(string $path): string
    {
        return file_get_contents(base_path('../frontend/src/'.$path));
    }

    public function test_professor_sheet_filters_blank_marks_and_guards_dirty_and_late_requests(): void
    {
        $source = $this->frontend('features/professor-dashboard/pages/ProfessorSupplementaryExams.jsx');

        $this->assertStringContainsString('requestSequenceRef', $source);
        $this->assertStringContainsString('setSheet(null)', $source);
        $this->assertStringContainsString('sequence !== requestSequenceRef.current', $source);
        $this->assertStringContainsString("normalizedMark(value) !== ''", $source);
        $this->assertStringContainsString('changedMarks', $source);
        $this->assertStringContainsString('dirty', $source);
        $this->assertStringContainsString('توجد تعديلات غير محفوظة', $source);
        $this->assertStringContainsString('SupplementaryConfirmDialog', $source);
        $this->assertStringNotContainsString('window.confirm', $source);
        $this->assertStringContainsString('action_flags?.can_submit', $source);
        $this->assertStringContainsString('action_flags?.can_resubmit', $source);
        $this->assertStringContainsString('!serverCanSubmit', $source);
        $this->assertStringNotContainsString('theoretical_mark:Number(theoretical_mark)', $source);
        $this->assertStringNotContainsString('theoretical_mark: Number(theoretical_mark)', $source);
    }

    public function test_professor_sheet_uses_server_editability_limits_and_complete_ui_states(): void
    {
        $source = $this->frontend('features/professor-dashboard/pages/ProfessorSupplementaryExams.jsx');

        $this->assertStringContainsString('serverCanEdit', $source);
        $this->assertStringContainsString("sheet?.action_flags?.can_edit === true", $source);
        $this->assertStringContainsString("periodStatus === 'grading_open'", $source);
        $this->assertStringContainsString('max={limits.max}', $source);
        $this->assertStringContainsString('min={limits.min}', $source);
        $this->assertStringContainsString('step={limits.step}', $source);
        $this->assertStringContainsString('جارٍ تحميل المقررات', $source);
        $this->assertStringContainsString('لا توجد مقررات تكميلية', $source);
        $this->assertStringContainsString('role="alert"', $source);
        $this->assertStringContainsString('ورقة العلامات للقراءة فقط', $source);
    }

    public function test_all_persisted_statuses_have_central_arabic_labels_without_raw_fallback(): void
    {
        $status = $this->frontend('features/supplementary-exams/supplementaryStatus.js');
        $consumers = [
            $this->frontend('features/professor-dashboard/pages/ProfessorSupplementaryExams.jsx'),
            $this->frontend('features/exam-board/pages/SupplementaryGradesPage.jsx'),
            $this->frontend('features/supplementary-exams/ReadOnlyRegistrationList.jsx'),
            $this->frontend('features/vice-presidency/utils/supplementaryExamPeriods.js'),
            $this->frontend('features/student-affairs/pages/SupplementaryExamRegistrations.jsx'),
        ];

        foreach ([
            'legacy', 'announced', 'registration_open', 'registration_closed',
            'grading_open', 'grading_submitted', 'results_approved',
            'results_published', 'results_materialized', 'waiting', 'draft',
            'submitted', 'returned', 'approved', 'published', 'materialized',
            'conflict', 'no_candidates', 'not_ready',
        ] as $state) {
            $this->assertStringContainsString($state, $status);
        }

        $this->assertStringNotContainsString('return String(status)', $status);
        foreach ($consumers as $consumer) {
            $this->assertStringContainsString('supplementaryStatus', $consumer);
        }
        $studentAffairs = $this->frontend('features/student-affairs/pages/SupplementaryExamRegistrations.jsx');
        $this->assertStringContainsString('periodStatusLabel(p.status)', $studentAffairs);
        $this->assertStringContainsString('periodStatusLabel(meta.period_status)', $studentAffairs);
        $this->assertStringContainsString('eligibilityReasonLabel(item.eligibility_reason)', $studentAffairs);
        $this->assertStringContainsString('eligibilityReasonLabel(row.eligibility_reason)', $studentAffairs);
        $this->assertStringNotContainsString('— {p.status}', $studentAffairs);
    }

    public function test_exam_office_has_operator_actions_counts_and_read_only_reconciliation(): void
    {
        $source = $this->frontend('features/exam-board/pages/SupplementaryGradesPage.jsx');
        $reconciliationStart = strpos($source, 'const loadReconciliation');
        $reconciliationEnd = strpos($source, 'useEffect(', $reconciliationStart);
        $reconciliationBlock = substr($source, $reconciliationStart, $reconciliationEnd - $reconciliationStart);

        $this->assertStringContainsString('/v1/exams/supplementary-periods/${selectedPeriodId}/reconciliation', $reconciliationBlock);
        $this->assertStringContainsString("{ method: 'GET' }", $reconciliationBlock);
        $this->assertStringNotContainsString("method: 'POST'", $reconciliationBlock);
        $this->assertStringContainsString('reconciliationRequestSequenceRef', $source);
        $this->assertStringContainsString('responsePeriodId', $source);
        $this->assertStringContainsString('reconciliationMatchesPeriod', $source);
        $this->assertStringNotContainsString('repair', strtolower($source));
        $this->assertStringContainsString('/v1/exams/supplementary-offerings/${offeringId}/graders', $source);
        $this->assertStringContainsString('/v1/exams/supplementary-offerings/${offeringId}/grader', $source);
        $this->assertStringContainsString('/v1/exams/supplementary-periods/${periodId}/open-grading', $source);
        $this->assertStringContainsString('SupplementaryConfirmDialog', $source);
        $this->assertStringNotContainsString('window.confirm', $source);
        $this->assertStringNotContainsString('window.prompt', $source);
        $this->assertStringContainsString('المسجلون', $source);
        $this->assertStringContainsString('أُدخلت علاماتهم', $source);
        $this->assertStringContainsString('المنشورة', $source);
        $this->assertStringContainsString('المُرحّلة رسمياً', $source);
        $this->assertStringContainsString('جارٍ تحميل طابور', $source);
        $this->assertStringContainsString('لا توجد عروض تكميلية', $source);
        $this->assertStringContainsString('للقراءة فقط', $source);
        $this->assertStringContainsString('materializationReasonLabel(materialization.reason)', $source);
        $this->assertStringContainsString("params.set('search', search)", $source);
        $this->assertStringContainsString('بحث وعرض المصححين', $source);
        $this->assertStringContainsString('disabled={graderLoading[offeringId] || rowBusy}', $source);
        $this->assertStringContainsString('reconciliationOfferings.map', $source);
        $this->assertStringContainsString('حالة كل عرض في الدورة المحددة', $source);
        $this->assertStringContainsString('operationalStatusLabel(report)', $source);
    }

    public function test_student_affairs_confirms_before_fixing_the_registration_roster(): void
    {
        $source = $this->frontend('features/student-affairs/pages/SupplementaryExamRegistrations.jsx');

        $this->assertStringContainsString("setDialog({ type: 'close' })", $source);
        $this->assertStringContainsString('SupplementaryConfirmDialog', $source);
        $this->assertStringNotContainsString('window.confirm', $source);
        $this->assertStringContainsString('تثبيت القائمة النهائية', $source);
    }

    public function test_exam_office_grader_picker_has_a_bounded_read_endpoint(): void
    {
        $routes = file_get_contents(base_path('routes/api.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/SupplementaryExamGradingController.php'));
        $service = file_get_contents(app_path('Services/SupplementaryExamGradingService.php'));

        $this->assertStringContainsString(
            "Route::get('exams/supplementary-offerings/{offering}/graders'",
            $routes,
        );
        $this->assertStringContainsString('public function graders(', $controller);
        $this->assertStringContainsString("'search' => ['nullable', 'string', 'max:100']", $controller);
        $this->assertStringContainsString('public function graderOptions(', $service);
        $this->assertStringContainsString('limit(50)', $service);
    }

    public function test_read_only_roster_distinguishes_loading_error_empty_and_fixed_later_states(): void
    {
        $source = $this->frontend('features/supplementary-exams/ReadOnlyRegistrationList.jsx');

        $this->assertStringContainsString('isFixedRosterStatus', $source);
        $this->assertStringContainsString("result?.list_status === 'fixed'", $source);
        $this->assertStringContainsString('جارٍ تحميل قائمة التسجيل', $source);
        $this->assertStringContainsString('لا توجد تسجيلات في هذه الدورة', $source);
        $this->assertStringContainsString('role="alert"', $source);
        $this->assertStringContainsString('هذه الشاشة للقراءة فقط', $source);
        $this->assertStringContainsString('requestSequenceRef', $source);
        $this->assertStringContainsString('sequence !== requestSequenceRef.current', $source);
        $this->assertStringContainsString('periodsLoading || listLoading', $source);
    }

    public function test_professor_shell_allows_the_supplementary_permission(): void
    {
        $source = $this->frontend('app/App.jsx');
        $this->assertStringContainsString(
            "permissions={['grades.manage', 'attendance.manage', 'supplementary_exams.grades.view']}",
            $source,
        );
    }

    public function test_supplementary_only_professor_lands_on_the_supplementary_workspace(): void
    {
        $source = $this->frontend('features/auth/auth.js');

        $this->assertStringContainsString("hasPermission('supplementary_exams.grades.view', user) && user?.employee_id", $source);
        $this->assertStringContainsString("return '/professor/supplementary-exams'", $source);
    }

    public function test_runbook_prohibits_manual_edits_and_distinguishes_rollback_from_grade_reversal(): void
    {
        $runbook = file_get_contents(base_path('docs/supplementary-exams-runbook.md'));

        $this->assertStringContainsString('Direct SQL changes', $runbook);
        $this->assertStringContainsString('Rollback of a deployment', $runbook);
        $this->assertStringContainsString('is not a grade reversal', $runbook);
        $this->assertStringContainsString('correction workflow', $runbook);
        $this->assertStringContainsString('no credentials', $runbook);
        $this->assertStringContainsString('`PASS`', $runbook);
        $this->assertStringContainsString('`WARNING`', $runbook);
        $this->assertStringContainsString('`CONFLICT`', $runbook);
    }
}
