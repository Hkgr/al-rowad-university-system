<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Source contracts for the simplified Dean semester-offering planner UX.
 */
class SimpleDeanOfferingPlannerContractTest extends TestCase
{
    public function test_simple_dean_01_complex_visible_bulk_controls_are_not_rendered(): void
    {
        $dean = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');

        self::assertStringNotContainsString('إجراءات جماعية', $dean);
        self::assertStringNotContainsString('تجهيز مستوى دراسي', $dean);
        self::assertStringNotContainsString('تجهيز جميع مواد البرنامج', $dean);
        self::assertStringNotContainsString('تحديد المواد يدويًا', $dean);
        self::assertStringNotContainsString('تحديد الكل', $dean);
        self::assertStringNotContainsString('إلغاء التحديد', $dean);
        self::assertStringNotContainsString('selectionMode', $dean);
        self::assertStringNotContainsString('advisory_semester', $dean);
        self::assertStringNotContainsString('advisory_level', $dean);
        self::assertStringNotContainsString('all_curriculum', $dean);
        self::assertStringNotContainsString('type="checkbox"', $dean);
    }

    public function test_simple_dean_02_top_actions_are_the_simplified_workflow(): void
    {
        $dean = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');

        self::assertStringContainsString('السنة الأكاديمية', $dean);
        self::assertStringContainsString('الفصل الفعلي', $dean);
        self::assertStringContainsString('القسم', $dean);
        self::assertStringContainsString('البرنامج', $dean);
        self::assertStringContainsString('تفريغ', $dean);
        self::assertStringContainsString('إضافة الخطة الإرشادية', $dean);
        self::assertStringContainsString('حفظ التجهيز', $dean);
    }

    public function test_simple_dean_03_advisory_plan_fills_matching_courses_locally(): void
    {
        $dean = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        $fillIds = self::extractJsFunction($dean, 'advisoryPlanDraftIds');
        $fill = self::extractJsFunction($dean, 'fillAdvisoryPlanDraft');
        $apply = self::extractJsFunction($dean, 'applyAdvisoryPlan');

        self::assertStringContainsString('recommendedSemesterMatches(row, selectedSemesterId)', $fillIds);
        self::assertStringContainsString('flattenCatalogCourses(levels)', $fillIds);
        self::assertStringNotContainsString('academic_level_id ===', $fillIds);
        self::assertStringNotContainsString('apiRequest', $fillIds);
        self::assertStringNotContainsString('apiRequest', $fill);
        self::assertStringContainsString('fillAdvisoryPlanDraft(current, levels, semesterId)', $apply);
        self::assertStringNotContainsString('apiRequest', $apply);
        self::assertStringNotContainsString('/bulk-prepare', $apply);
    }

    public function test_simple_dean_04_advisory_plan_fill_is_idempotent_by_program_course_id(): void
    {
        $dean = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        $unique = self::extractJsFunction($dean, 'uniqueProgramCourseIds');
        $fill = self::extractJsFunction($dean, 'fillAdvisoryPlanDraft');

        self::assertStringContainsString('seen.has(id)', $unique);
        self::assertStringContainsString('uniqueProgramCourseIds([', $fill);
        self::assertStringContainsString('advisoryPlanDraftIds(levels, selectedSemesterId)', $fill);
    }

    public function test_simple_dean_05_clear_draft_removes_unsaved_selections_only(): void
    {
        $dean = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        $row = self::extractJsFunction($dean, 'PlannerRow');

        self::assertStringContainsString("type: 'clear-draft'", $dean);
        self::assertStringContainsString('setDraftIds([])', $dean);
        self::assertStringContainsString('Boolean(row.offering) || draftSet.has', $dean);
        self::assertStringContainsString('محفوظ مسبقًا', $row);
        self::assertStringContainsString('غير محفوظ', $row);
        self::assertStringContainsString('سيتم تفريغ التجهيز غير المحفوظ فقط.', $dean);
        self::assertStringContainsString('لن يتم حذف أي طروحات أو بيانات أكاديمية محفوظة.', $dean);
    }

    public function test_simple_dean_06_clear_draft_does_not_mutate_backend(): void
    {
        $dean = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        $clearPos = strpos($dean, "if (confirm.type === 'clear-draft')");
        self::assertNotFalse($clearPos);
        $clearBlock = substr($dean, $clearPos, 280);

        self::assertStringContainsString('setDraftIds([])', $clearBlock);
        self::assertStringNotContainsString('apiRequest', $clearBlock);
        self::assertStringNotContainsString('DELETE', $clearBlock);
        self::assertStringNotContainsString('bulk-prepare', $clearBlock);
        self::assertStringNotContainsString('method: \'DELETE\'', $dean);
        self::assertStringNotContainsString('forceDelete', $dean);
    }

    public function test_simple_dean_07_add_course_exists_at_each_academic_level(): void
    {
        $dean = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');

        self::assertStringContainsString('{levels.map(level => {', $dean);
        self::assertStringContainsString('+ إضافة مادة', $dean);
        self::assertStringContainsString('plannerRowsForLevel(level, draftIds)', $dean);
        self::assertStringContainsString('لا توجد مواد في التجهيز', $dean);
        self::assertStringContainsString('setPicker({', $dean);
        self::assertStringContainsString('courses: level.courses ?? []', $dean);
    }

    public function test_simple_dean_08_add_dialog_can_select_a_course_recommended_for_another_semester(): void
    {
        $dean = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        $dialog = self::extractJsFunction($dean, 'AddCourseDialog');
        $label = self::extractJsFunction($dean, 'advisorySemesterLabel');

        self::assertStringContainsString('إضافة مادة — {levelName}', $dialog);
        self::assertStringContainsString('ابحث باسم المادة أو رمزها', $dialog);
        self::assertStringContainsString('advisorySemesterLabel(row)', $dialog);
        self::assertStringContainsString('إرشاديًا: ${name}', $label);
        self::assertStringContainsString('إرشاديًا: فصل آخر', $label);
        self::assertStringContainsString('const blocked = persisted || added', $dialog);
        self::assertStringNotContainsString('recommendedSemesterMatches', $dialog);
    }

    public function test_simple_dean_09_advisory_mismatch_does_not_disable_adding_to_the_draft(): void
    {
        $dean = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        $dialog = self::extractJsFunction($dean, 'AddCourseDialog');

        self::assertDoesNotMatchRegularExpression('/disabled=\{[^}]*advisory/', $dialog);
        self::assertDoesNotMatchRegularExpression('/disabled=\{[^}]*recommended/', $dialog);
        self::assertStringNotContainsString('فتح استثنائي', $dean);
        self::assertStringContainsString('disabled={blocked}', $dialog);
        self::assertStringContainsString('addCourseToDraft', $dean);
    }

    public function test_simple_dean_10_save_uses_existing_bulk_prepare_selected_mode(): void
    {
        $dean = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        $save = self::extractJsFunction($dean, 'saveDraft');

        self::assertStringContainsString("/v1/dean/registration-offerings/bulk-prepare", $save);
        self::assertStringContainsString("mode: 'selected'", $save);
        self::assertStringContainsString('program_course_ids: preview.programCourseIds', $save);
        self::assertStringNotContainsString('advisory_semester', $save);
        self::assertStringNotContainsString('all_curriculum', $save);
    }

    public function test_simple_dean_11_save_sends_selected_actual_year_and_semester(): void
    {
        $dean = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        $save = self::extractJsFunction($dean, 'saveDraft');

        self::assertStringContainsString('academic_year_id: Number(yearId)', $save);
        self::assertStringContainsString('semester_id: Number(semesterId)', $save);
        self::assertStringContainsString('academic_program_id: Number(programId)', $save);
        self::assertStringNotContainsString('recommended_semester_id', $save);
        self::assertStringNotContainsString('status:', $save);
    }

    public function test_simple_dean_12_new_offerings_remain_closed_via_existing_backend(): void
    {
        $dean = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        $save = self::extractJsFunction($dean, 'saveDraft');
        $find = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'findOrCreateClosedOffering'
        );

        self::assertStringNotContainsString("'status'", $save);
        self::assertStringNotContainsString('status: \'open\'', $save);
        self::assertStringContainsString("'status' => self::STATUS_CLOSED", $find);
        self::assertStringNotContainsString('normalOpen(', $save);
    }

    public function test_simple_dean_13_existing_offerings_are_not_recreated_or_status_mutated(): void
    {
        $dean = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        $save = self::extractJsFunction($dean, 'saveDraft');
        $row = self::extractJsFunction($dean, 'PlannerRow');

        self::assertStringNotContainsString('/open', $save);
        self::assertStringNotContainsString('/close', $save);
        self::assertStringNotContainsString('method: \'DELETE\'', $dean);
        self::assertStringContainsString('إدارة الطرح', $row);
        self::assertStringNotContainsString('إعادة فتح', $row);
        self::assertStringNotContainsString('إغلاق التسجيل', $row);
        self::assertStringNotContainsString('طلب فتح استثنائي', $dean);
    }

    public function test_simple_dean_14_after_save_catalog_refresh_has_no_browser_reload(): void
    {
        $dean = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        $save = self::extractJsFunction($dean, 'saveDraft');

        self::assertStringContainsString('تم حفظ تجهيز الفصل بنجاح.', $save);
        self::assertStringContainsString('reloadCatalog()', $save);
        self::assertStringNotContainsString('window.location.reload', $dean);
        self::assertStringNotContainsString('location.reload', $dean);
    }

    public function test_simple_dean_15_changing_program_year_or_semester_clears_stale_draft(): void
    {
        $dean = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');

        self::assertStringContainsString('setDraftIds([])', $dean);
        self::assertStringContainsString('[programId, semesterId, yearId]', $dean);
        self::assertGreaterThan(
            1,
            substr_count($dean, 'setDraftIds([])')
        );
    }

    public function test_simple_dean_16_hardened_dean_mutation_gate_remains_unchanged(): void
    {
        $canManage = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'canManage'
        );
        $assert = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'assertCanManage'
        );
        $bulk = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'bulkPrepare'
        );

        self::assertStringContainsString('if (! $user->isDean())', $canManage);
        self::assertStringContainsString('$user->effectivePermissions()', $canManage);
        self::assertStringContainsString("\$permissions->contains('course_offerings.manage')", $canManage);
        self::assertStringContainsString("\$permissions->contains('courses.manage')", $canManage);
        self::assertStringNotContainsString('hasPermission(', $canManage);
        self::assertStringContainsString('canManage($user)', $assert);
        self::assertStringContainsString('assertCanManage($user)', $bulk);
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
