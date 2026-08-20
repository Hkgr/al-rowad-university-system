<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Source contracts: simplified planner still has a path to open offerings.
 */
class OfferingManagementContractTest extends TestCase
{
    public function test_offering_manage_01_planner_exposes_manage_entry_not_workflow_buttons(): void
    {
        $planner = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');
        $row = self::extractJsFunction($planner, 'PlannerRow');

        self::assertStringContainsString('إدارة الطرح', $row);
        self::assertStringContainsString('/dean/courses/${id}', $planner);
        self::assertDoesNotMatchRegularExpression('/>\s*فتح التسجيل\s*</u', $planner);
        self::assertStringNotContainsString('طلب فتح استثنائي', $planner);
        self::assertStringNotContainsString('طلب إغلاق', $planner);
        self::assertStringNotContainsString('إدارة تكليف المدرسين', $planner);
        self::assertStringNotContainsString('إجراءات جماعية', $planner);
        self::assertStringContainsString('تفريغ', $planner);
        self::assertStringContainsString('إضافة الخطة الإرشادية', $planner);
        self::assertStringContainsString('حفظ التجهيز', $planner);
    }

    public function test_offering_manage_02_closed_complete_coverage_provides_normal_open(): void
    {
        $panel = self::frontend('src/features/dean-dashboard/components/DeanOfferingStatusPanel.jsx');
        $profile = self::frontend('src/features/dean-dashboard/pages/DeanCourseOfferingProfile.jsx');
        $canOpen = self::extractJsFunction($panel, 'canNormalOpenOffering');
        $open = self::extractJsFunction($profile, 'openRegistration');

        self::assertStringContainsString("offering?.status === 'closed'", $canOpen);
        self::assertStringContainsString('instructorCoverageComplete(offering?.instructor_coverage)', $canOpen);
        self::assertStringContainsString('فتح التسجيل', $panel);
        self::assertStringContainsString('canNormalOpenOffering(offering)', $panel);
        self::assertStringContainsString('`/v1/dean/registration-offerings/${id}/open`', $open);
        self::assertStringContainsString("method: 'POST'", $open);
        self::assertStringContainsString('loadOffering()', $open);
        self::assertStringNotContainsString('window.location.reload', $open);
    }

    public function test_offering_manage_03_incomplete_coverage_does_not_enable_normal_opening(): void
    {
        $panel = self::frontend('src/features/dean-dashboard/components/DeanOfferingStatusPanel.jsx');
        $canOpen = self::extractJsFunction($panel, 'canNormalOpenOffering');

        self::assertStringContainsString('instructorCoverageComplete(offering?.instructor_coverage)', $canOpen);
        self::assertStringContainsString('showNormalOpen = canManage && canNormalOpenOffering(offering)', $panel);
        self::assertStringContainsString('{showNormalOpen && (', $panel);
        self::assertStringContainsString('بانتظار استكمال تكليف المدرسين', $panel);
        self::assertStringNotContainsString('disabled={closed && !coverageComplete}', $panel);
    }

    public function test_offering_manage_04_incomplete_coverage_can_request_exceptional_opening(): void
    {
        $panel = self::frontend('src/features/dean-dashboard/components/DeanOfferingStatusPanel.jsx');
        $profile = self::frontend('src/features/dean-dashboard/pages/DeanCourseOfferingProfile.jsx');
        $canRequest = self::extractJsFunction($panel, 'canRequestExceptionalOpening');
        $submit = self::extractJsFunction($profile, 'submitException');

        self::assertStringContainsString("offering?.status === 'closed'", $canRequest);
        self::assertStringContainsString('!instructorCoverageComplete(offering?.instructor_coverage)', $canRequest);
        self::assertStringContainsString('طلب فتح استثنائي', $panel);
        self::assertStringContainsString('/v1/dean/course-offering-exceptions', $submit);
        self::assertStringContainsString('course_offering_id: Number(id)', $submit);
        self::assertStringContainsString('reason', $submit);
        self::assertStringContainsString('/resubmit', $submit);
    }

    public function test_offering_manage_05_exceptional_opening_is_not_automatic(): void
    {
        $profile = self::frontend('src/features/dean-dashboard/pages/DeanCourseOfferingProfile.jsx');
        $submit = self::extractJsFunction($profile, 'submitException');

        self::assertStringContainsString('const loadExceptionRequest = useCallback', $profile);
        self::assertStringContainsString('/v1/dean/course-offering-exceptions?', $profile);
        self::assertStringContainsString("method: 'POST'", $submit);
        self::assertStringContainsString('confirm.type === \'exception-resubmit\'', $profile);
        self::assertGreaterThan(
            strpos($profile, 'const loadExceptionRequest = useCallback'),
            strpos($profile, 'async function submitException')
        );
        self::assertStringNotContainsString(
            "apiRequest('/v1/dean/course-offering-exceptions'",
            substr($profile, 0, (int) strpos($profile, 'async function submitException'))
        );
    }

    public function test_offering_manage_06_open_offering_does_not_show_enabled_normal_open(): void
    {
        $panel = self::frontend('src/features/dean-dashboard/components/DeanOfferingStatusPanel.jsx');
        $canOpen = self::extractJsFunction($panel, 'canNormalOpenOffering');

        self::assertStringContainsString("offering?.status === 'closed'", $canOpen);
        self::assertStringNotContainsString("status === 'open'", $canOpen);
        self::assertStringContainsString('التسجيل مفتوح', $panel);
        self::assertStringContainsString('{open && (', $panel);
        self::assertStringContainsString('{showNormalOpen && (', $panel);
    }

    public function test_offering_manage_07_no_direct_course_offering_status_mutation_in_frontend(): void
    {
        $profile = self::frontend('src/features/dean-dashboard/pages/DeanCourseOfferingProfile.jsx');
        $panel = self::frontend('src/features/dean-dashboard/components/DeanOfferingStatusPanel.jsx');
        $planner = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');

        foreach ([$profile, $panel, $planner] as $source) {
            self::assertStringNotContainsString("status: 'open'", $source);
            self::assertStringNotContainsString('status: "open"', $source);
            self::assertStringNotContainsString("offering.status =", $source);
            self::assertStringNotContainsString('method: \'PATCH\'', $source);
            self::assertStringNotContainsString('method: \'PUT\'', $source);
            self::assertStringNotContainsString('/registration-offerings/${id}/close', $source);
        }
    }

    public function test_offering_manage_08_planner_simple_dean_contracts_remain_valid(): void
    {
        $planner = self::frontend('src/features/dean-dashboard/pages/DeanRegistrationOfferings.jsx');

        self::assertStringNotContainsString('إجراءات جماعية', $planner);
        self::assertStringContainsString('تفريغ', $planner);
        self::assertStringContainsString('إضافة الخطة الإرشادية', $planner);
        self::assertStringContainsString('حفظ التجهيز', $planner);
        self::assertStringContainsString("mode: 'selected'", $planner);
        self::assertStringContainsString('fillAdvisoryPlanDraft', $planner);
        self::assertStringContainsString('setDraftIds([])', $planner);
        self::assertStringContainsString('+ إضافة مادة', $planner);
        self::assertStringNotContainsString('window.location.reload', $planner);
    }

    public function test_offering_manage_09_hardened_dean_mutation_gate_remains_intact(): void
    {
        $canManage = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'canManage'
        );
        $reopen = self::extractMethod(
            self::source('app/Services/DeanRegistrationOfferingService.php'),
            'reopenOffering'
        );

        self::assertStringContainsString('if (! $user->isDean())', $canManage);
        self::assertStringContainsString('$user->effectivePermissions()', $canManage);
        self::assertStringNotContainsString('hasPermission(', $canManage);
        self::assertStringContainsString('assertCanManage($user)', $reopen);
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
