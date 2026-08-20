<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Behavioral contracts for the Dean semester-preparation planner UX.
 */
class DeanOfferingPlannerUxContractTest extends TestCase
{
    public function test_ux_plan_01_to_11_advisory_and_draft_behavior(): void
    {
        $script = dirname(__DIR__, 1).'/Feature/deanOfferingPlanner.behavior.mjs';
        $output = [];
        $code = 0;
        exec('node '.escapeshellarg($script).' 2>&1', $output, $code);
        $raw = implode("\n", $output);
        self::assertSame(0, $code, $raw);
        $payload = json_decode($raw, true);
        self::assertIsArray($payload);
        self::assertTrue($payload['ok'] ?? false, $raw);
        self::assertGreaterThanOrEqual(10, (int) ($payload['count'] ?? 0));
    }

    public function test_ux_plan_01_page_wires_advisory_click_without_backend_post(): void
    {
        $page = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        $apply = self::extractJsFunction($page, 'applyAdvisoryPlanClick');
        $util = self::frontend('src/features/dean-dashboard/utils/deanOfferingPlanner.js');

        self::assertStringContainsString('applyAdvisoryPlan(draftIds, levels, semesterId)', $apply);
        self::assertStringNotContainsString('apiRequest', $apply);
        self::assertStringNotContainsString('bulk-prepare', $apply);
        self::assertStringContainsString('kind === \'missing-metadata\'', $apply);
        self::assertStringContainsString('kind === \'zero-match\'', $apply);
        self::assertStringContainsString("showNotice(result.notice, 'success')", $apply);
        self::assertStringContainsString('export function applyAdvisoryPlan', $util);
        self::assertStringContainsString('row?.advisory_plan?.recommended_semester_id', $util);
    }

    public function test_ux_plan_02_zero_match_uses_warning_not_success(): void
    {
        $page = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        $apply = self::extractJsFunction($page, 'applyAdvisoryPlanClick');
        $util = self::frontend('src/features/dean-dashboard/utils/deanOfferingPlanner.js');

        self::assertStringContainsString("showNotice(result.notice || ADVISORY_NOTICE.zeroMatch, 'warning')", $apply);
        self::assertStringContainsString('لم يتم العثور على مواد مرتبطة بالفصل المحدد في الخطة الإرشادية.', $util);
        self::assertStringNotContainsString("showNotice(result.notice, 'success')", str_replace("showNotice(result.notice, 'success')", '', $apply));
        self::assertGreaterThan(
            (int) strpos($apply, 'zero-match'),
            (int) strpos($apply, "showNotice(result.notice, 'success')")
        );
    }

    public function test_ux_plan_03_missing_metadata_warning_is_distinct(): void
    {
        $util = self::frontend('src/features/dean-dashboard/utils/deanOfferingPlanner.js');
        $page = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        $apply = self::extractJsFunction($page, 'applyAdvisoryPlanClick');

        self::assertStringContainsString('تعذّر قراءة الفصل الإرشادي من بيانات الخطة', $util);
        self::assertStringContainsString('kind: \'missing-metadata\'', $util);
        self::assertStringContainsString("showNotice(result.notice || ADVISORY_NOTICE.missingMetadata, 'warning')", $apply);
        self::assertStringContainsString('noticeTone === \'warning\'', $page);
        self::assertStringContainsString('bg-amber-50 text-amber-800', $page);
    }

    public function test_ux_plan_04_academic_years_stay_visible_when_empty(): void
    {
        $page = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        $util = self::frontend('src/features/dean-dashboard/utils/deanOfferingPlanner.js');

        self::assertStringContainsString('rowsByAcademicLevel(levels, draftIds)', $page);
        self::assertStringContainsString('لم تتم إضافة مواد إلى تجهيز هذه السنة بعد.', $page);
        self::assertStringContainsString('مادة في الخطة', $page);
        self::assertStringContainsString('level.curriculumCount', $page);
        self::assertStringContainsString('plannerRowsForLevel', $util);
        self::assertStringNotContainsString('الخطة فارغة', $page);
    }

    public function test_ux_plan_06_add_dialog_keeps_off_semester_courses_selectable(): void
    {
        $page = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        $dialog = self::extractJsFunction($page, 'AddCourseDialog');

        self::assertStringContainsString('إضافة مادة — {level?.level_name}', $dialog);
        self::assertStringContainsString('ابحث باسم المادة أو رمزها', $dialog);
        self::assertStringContainsString('const blocked = persisted || added', $dialog);
        self::assertDoesNotMatchRegularExpression('/disabled=\{[^}]*advisory/', $dialog);
        self::assertStringNotContainsString('disabled={blocked || recommended', $dialog);
        self::assertStringContainsString('coursesForAcademicLevel(levels, addLevel.academic_level_id)', $page);
        self::assertStringContainsString('setAddLevel(null)', $page);
        self::assertStringNotContainsString('apiRequest', self::extractJsFunction($page, 'addCourseToDraft'));
    }

    public function test_ux_plan_07_clear_uses_confirm_and_does_not_delete_offerings(): void
    {
        $page = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        $util = self::frontend('src/features/dean-dashboard/utils/deanOfferingPlanner.js');

        self::assertStringContainsString('type: \'clear-draft\'', $page);
        self::assertStringContainsString('CLEAR_DRAFT_WARNING', $page);
        self::assertStringContainsString('سيتم حذف المواد غير المحفوظة من التجهيز الحالي فقط.', $util);
        self::assertStringContainsString('لن يتم حذف أي طرح محفوظ أو بيانات أكاديمية.', $util);
        self::assertStringContainsString("setDraftIds([])", $page);
        self::assertStringNotContainsString('method: \'DELETE\'', $page);
        self::assertStringNotContainsString('/close', self::extractJsFunction($page, 'applyAdvisoryPlanClick'));
    }

    public function test_ux_plan_09_save_uses_selected_mode_and_reloads_catalog(): void
    {
        $page = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        $save = self::extractJsFunction($page, 'savePreparation');

        self::assertStringContainsString('/v1/dean/registration-offerings/bulk-prepare', $save);
        self::assertStringContainsString("mode: 'selected'", $save);
        self::assertStringContainsString('program_course_ids: preview.programCourseIds', $save);
        self::assertStringContainsString('reloadCatalog()', $save);
        self::assertStringNotContainsString('window.location.reload', $save);
        self::assertStringContainsString('تم حفظ تجهيز الفصل بنجاح.', $save);
        self::assertStringNotContainsString("mode: 'advisory_semester'", $page);
        self::assertStringNotContainsString("mode: 'all_curriculum'", $page);
        self::assertStringNotContainsString('إجراءات جماعية', $page);
    }

    public function test_ux_plan_10_new_offerings_remain_closed_on_backend(): void
    {
        $find = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'findOrCreateClosedOffering'
        );
        $bulk = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'bulkPrepare'
        );

        self::assertStringContainsString("'status' => self::STATUS_CLOSED", $find);
        self::assertStringNotContainsString('normalOpen(', $bulk);
        self::assertStringNotContainsString("'status' => 'open'", $bulk);
    }

    public function test_ux_plan_11_existing_offering_states_remain_unchanged(): void
    {
        $find = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'findOrCreateClosedOffering'
        );

        self::assertStringContainsString("'created' => false", $find);
        self::assertStringNotContainsString('$offering->status =', $find);
        self::assertStringNotContainsString('$offering->update(', $find);
    }

    public function test_ux_plan_12_page_retains_dean_dashboard_visual_conventions(): void
    {
        $page = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        $courses = self::frontend('src/features/dean-dashboard/pages/DeanCourses.jsx');
        $profile = self::frontend('src/features/dean-dashboard/pages/DeanCourseOfferingProfile.jsx');
        $dialog = self::frontend('src/features/dean-dashboard/components/DeanConfirmDialog.jsx');

        self::assertStringContainsString('text-[20px] font-black text-text-dark', $page);
        self::assertStringContainsString('text-[20px] font-black text-text-dark', $courses);
        self::assertStringContainsString('text-[12.5px] text-text-light', $page);
        self::assertStringContainsString('text-[12.5px] text-text-light', $courses);
        self::assertStringContainsString('bg-white border border-primary/12 rounded-[16px]', $page);
        self::assertStringContainsString('shadow-[0_2px_10px_rgba(26,46,16,0.05)]', $page);
        self::assertStringContainsString('border border-primary/12 rounded-[14px] bg-white', $page);
        self::assertStringContainsString('px-3 py-2 bg-primary text-white rounded-[10px] text-[12.5px] font-bold', $page);
        self::assertStringContainsString('fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-black/45', $page);
        self::assertStringContainsString('fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-black/45', $dialog);
        self::assertStringContainsString('rounded-t-[18px] sm:rounded-[18px]', $page);
        self::assertStringContainsString('rounded-t-[18px] sm:rounded-[18px]', $dialog);
        self::assertStringContainsString('DeanConfirmDialog', $page);
        self::assertStringContainsString('CourseRequirementBadges', $page);
        self::assertStringContainsString('statusBadgeClass', $profile);
        self::assertStringContainsString('فتح المواد للتسجيل', $page);
        self::assertStringContainsString('جهّز مواد الفصل ثم تابع إدارة كل طرح بعد الحفظ.', $page);
        self::assertStringContainsString('تجهيز مواد الفصل', $page);
        self::assertStringContainsString('حفظ التجهيز', $page);
        self::assertStringContainsString('إدارة الطرح', $page);
        self::assertStringNotContainsString('selectionMode', $page);
        self::assertStringNotContainsString('type="checkbox"', $page);
    }

    public function test_ux_plan_existing_offering_management_is_preserved(): void
    {
        $page = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        $profile = self::frontend('src/features/dean-dashboard/pages/DeanCourseOfferingProfile.jsx');

        self::assertStringContainsString('`/v1/dean/registration-offerings/${row.offering.course_offering_id}/open`', $page);
        self::assertStringContainsString('/v1/dean/course-offering-exceptions', $page);
        self::assertStringContainsString('إدارة تكليف المدرسين', $page);
        self::assertStringContainsString('/dean/courses/${id}', $page);
        self::assertFileDoesNotExist(dirname(__DIR__, 3).'/frontend/src/features/dean-dashboard/components/DeanOfferingStatusPanel.jsx');
        self::assertStringContainsString('DeanCourseTeachersPanel', $profile);
        self::assertStringContainsString('/v1/dean/course-offerings/${id}', $profile);
    }

    private static function source(string $path): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }

    private static function frontend(string $path): string
    {
        return file_get_contents(dirname(__DIR__, 3).'/frontend/'.$path);
    }

    private static function extractMethod(string $source, string $name): string
    {
        self::assertSame(
            1,
            preg_match(
                '/\n    (?:private|public|protected) function '.preg_quote($name, '/').'\(.*?(?=\n    (?:private|public|protected) function |\n\}\s*\z)/s',
                $source,
                $matches
            ),
            "Expected exactly one method {$name}()."
        );

        return $matches[0];
    }

    private static function extractJsFunction(string $source, string $name): string
    {
        $start = strpos($source, 'function '.$name.'(');
        self::assertNotFalse($start, "Expected function {$name}().");
        if (! preg_match('/\n(?:export default )?function |\n  (?:async )?function /', $source, $matches, PREG_OFFSET_CAPTURE, $start + 1)) {
            return substr($source, $start);
        }

        return substr($source, $start, $matches[0][1] - $start);
    }
}
