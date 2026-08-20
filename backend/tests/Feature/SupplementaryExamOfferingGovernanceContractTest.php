<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class SupplementaryExamOfferingGovernanceContractTest extends TestCase
{
    public function test_dean_authorization_uses_assigned_permission_not_has_permission(): void
    {
        $service = self::source('app/Services/SupplementaryExamOfferingService.php');
        $manage = self::extractMethod($service, 'assertCanManage');
        $view = self::extractMethod($service, 'assertCanView');
        self::assertStringContainsString('isDean()', $manage);
        self::assertStringContainsString('holdsAssignedPermission($user, SupplementaryExamOfferingGovernance::PERMISSION_MANAGE)', $manage);
        self::assertStringNotContainsString('hasPermission(', $manage);
        self::assertStringContainsString('isDean()', $view);
        self::assertStringNotContainsString('hasPermission(', $view);

        $holds = self::extractMethod($service, 'holdsAssignedPermission');
        self::assertStringContainsString('effectivePermissions()->contains($permission)', $holds);

        $request = self::source('app/Http/Requests/Dean/StoreSupplementaryExamOfferingRequest.php');
        self::assertStringContainsString('isDean()', $request);
        self::assertStringContainsString('effectivePermissions()->contains(SupplementaryExamOfferingGovernance::PERMISSION_MANAGE)', $request);
        self::assertStringNotContainsString('hasPermission(', $request);
    }

    public function test_source_eligibility_centralized_and_uses_historical_attempt_statuses(): void
    {
        $source = self::source('app/Services/SupplementaryExamOfferingSourceService.php');
        self::assertStringContainsString('StudentCourseRegistration::HISTORICAL_ATTEMPT_STATUSES', $source);
        self::assertStringContainsString('allowedSourceSemesterOrders($period)', $source);
        self::assertStringContainsString('whereIn(\'course_offering_id\', CourseOffering::idsResolvedToColleges($collegeIds))', $source);
        self::assertStringNotContainsString('ProgramCourse', $source);
        self::assertStringNotContainsString("status = 'OPEN'", strtoupper($source));
        self::assertStringNotContainsString("where('status', 'open')", $source);
        self::assertStringNotContainsString('semester_id = 1', $source);
        self::assertStringNotContainsString('semester_id = 2', $source);
        self::assertStringNotContainsString('semester_id = 3', $source);
        self::assertStringNotContainsString('where(\'semester_id\'', $source);

        $policy = self::source('app/Support/SupplementaryExamPolicy.php');
        self::assertStringContainsString('self::SEMESTER_ORDER_FIRST => [self::SEMESTER_ORDER_FIRST]', $policy);
        self::assertStringContainsString('self::SEMESTER_ORDER_SUMMER => [', $policy);
        self::assertStringContainsString('SUMMER_MAX_COURSES_PER_STUDENT = 3', $policy);
        self::assertStringNotContainsString('semester_id', $policy);
    }

    public function test_schema_ready_inspects_builder_not_schema_class(): void
    {
        $source = self::source('app/Support/SupplementaryExamOfferingGovernance.php');
        self::assertStringContainsString('Schema::connection((string) config(\'database.default\'))', $source);
        self::assertStringContainsString('method_exists($builder, \'getIndexes\')', $source);
        self::assertStringNotContainsString("method_exists(Schema::class, 'getIndexes')", $source);
        self::assertStringContainsString('supplementary_exam_period_id\', \'academic_program_id\', \'course_id\'', $source);
        self::assertStringContainsString('supplementary_exam_offering_id\', \'course_offering_id\'', $source);
    }

    public function test_no_phase_3_student_registration_or_grading(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            'app/Models/SupplementaryExamRegistration.php',
            'app/Services/SupplementaryExamEligibilityService.php',
            'app/Services/SupplementaryExamGradingService.php',
        ] as $path) {
            self::assertFileDoesNotExist($root.'/'.$path);
        }
        $service = self::source('app/Services/SupplementaryExamOfferingService.php');
        self::assertStringNotContainsString('failed_theoretical', $service);
        self::assertStringNotContainsString('StudentCourseRegistration::query()->create', $service);
        self::assertStringNotContainsString('CourseOffering::query()->create', $service);
        self::assertStringNotContainsString('maxCoursesPerStudent', self::extractMethod($service, 'openInsideTransaction'));
    }

    public function test_frontend_dean_page_and_nav(): void
    {
        $app = self::frontend('src/app/App.jsx');
        self::assertStringContainsString('path="/dean/supplementary-exams"', $app);
        self::assertStringContainsString('DeanSupplementaryExams', $app);

        $nav = self::frontend('src/features/dean-dashboard/nav.js');
        self::assertStringContainsString("to: '/dean/supplementary-exams'", $nav);
        self::assertStringContainsString("ar: 'الامتحانات التكميلية'", $nav);

        $page = self::frontend('src/features/dean-dashboard/pages/DeanSupplementaryExams.jsx');
        self::assertStringContainsString('DeanConfirmDialog', $page);
        self::assertStringContainsString('/v1/dean/supplementary-exam-offerings/catalog', $page);
        self::assertStringContainsString('canManageSupplementaryExamOfferings', $page);
        self::assertStringContainsString('لا توجد مواد مستوفية لشروط الطرح التكميلي لهذا البرنامج ضمن نطاق', $page);
        self::assertStringContainsString('الدورة التكميلية للفصل الثالث / الصيفي', $page);
        self::assertStringContainsString('في هذه الدورة يحق للطالب لاحقًا التسجيل في ثلاث مواد كحد أقصى.', $page);
        self::assertStringContainsString('طرح في التكميلي', $page);
        self::assertStringContainsString('إعادة فتح', $page);
        self::assertStringNotContainsString('ProgramCourse', $page);
        self::assertStringNotContainsString('طرح جميع مواد الخطة', $page);
        self::assertStringNotContainsString('deleteJson', $page);
        self::assertStringNotContainsString('method: \'DELETE\'', $page);
        self::assertStringNotContainsString('student-registration', $page);
        self::assertStringNotContainsString('failed_theoretical', $page);
        self::assertStringNotContainsString('hasPermission(', $page);

        $utils = self::frontend('src/features/dean-dashboard/utils/supplementaryExamOfferings.js');
        self::assertStringContainsString('hasAssignedPermission', $utils);
        self::assertStringContainsString("hasRole(ROLES.dean, user)", $utils);
        self::assertStringNotContainsString('hasPermission(', $utils);
    }

    public function test_no_migrations_or_seeders(): void
    {
        $migrations = glob(dirname(__DIR__, 2).'/database/migrations/*supplementary*offering*') ?: [];
        self::assertSame([], $migrations);
        $seeders = glob(dirname(__DIR__, 2).'/database/seeders/*Supplementary*Offering*') ?: [];
        self::assertSame([], $seeders);
    }

    public function test_routes_have_no_delete(): void
    {
        $routes = self::source('routes/api.php');
        self::assertStringContainsString("get('dean/supplementary-exam-offerings/catalog'", $routes);
        self::assertStringContainsString("post('dean/supplementary-exam-offerings'", $routes);
        self::assertStringContainsString("post('dean/supplementary-exam-offerings/{offering}/close'", $routes);
        self::assertStringContainsString("post('dean/supplementary-exam-offerings/{offering}/reopen'", $routes);
        self::assertStringNotContainsString("delete('dean/supplementary-exam-offerings", $routes);
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
}
