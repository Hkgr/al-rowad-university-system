<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Deployment/authorization contract tests that do not require the missing P0-2 schema.
 * Database-backed access tests remain mandatory in CI once that schema is available.
 */
class P01AuthorizationClosureTest extends TestCase
{
    private static function source(string $path): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }

    public function test_every_v1_route_is_behind_sanctum_and_registration_mutations_require_permission(): void
    {
        $routes = self::source('routes/api.php');
        self::assertStringContainsString("Route::middleware(['auth:sanctum'", $routes);
        self::assertStringContainsString("RequirePermission::class.':registration.manage'", $routes);
    }

    public function test_registration_uses_authenticated_actor_and_checks_both_record_scopes(): void
    {
        $controller = self::source('app/Http/Controllers/Api/RegistrationController.php');
        $service = self::source('app/Services/RegistrationService.php');
        self::assertStringContainsString('canAccessStudent', $controller);
        self::assertStringContainsString('canAccessOffering', $controller);
        self::assertStringContainsString('$registeredByUserId = $authenticatedUserId;', $service);
        self::assertStringNotContainsString('$data[\'registered_by_user_id\'] ??', $service);
    }

    public function test_all_offering_lists_and_registration_queries_apply_scope(): void
    {
        $offering = self::source('app/Http/Controllers/Api/CourseOfferingController.php');
        self::assertGreaterThanOrEqual(4, substr_count($offering, 'scopeOfferings('));
        self::assertStringContainsString('scopeRegistrations(', self::source('app/Http/Controllers/Api/StudentCourseRegistrationController.php'));
        self::assertStringContainsString('scopeStudents(', self::source('app/Http/Controllers/Api/StudentAffairsDashboardController.php'));
    }

    #[DataProvider('sqlContractProvider')]
    public function test_sql_first_contract(string $file, string $needle): void
    {
        self::assertStringContainsString($needle, self::source('database/sql/p0-1/'.$file));
    }

    public static function sqlContractProvider(): array
    {
        return [
            'real operational users' => ['00_preflight.sql', "'registrar','exam.board'"],
            'case-sensitive identities' => ['00_preflight.sql', "BINARY username=BINARY 'exam.board'"],
            'idempotent table' => ['01_apply.sql', 'CREATE TABLE IF NOT EXISTS user_access_scopes'],
            'presidency is seeded' => ['01_apply.sql', "'PRES','رئيس الجامعة'"],
            'administration 735' => ['01_apply.sql', "'735','إدارة الامتحانات'"],
            'correct 736 name' => ['01_apply.sql', "'736','مكتب التوثيق والتصديق'"],
            'scientific branch' => ['01_apply.sql', "'8','نائب رئيس الجامعة للشؤون العلمية'"],
            'community centers' => ['01_apply.sql', "'911','مركز التأهيل والتدريب'"],
            'last official office' => ['01_apply.sql', "'925','مكتب العدالة وحقوق الإنسان'"],
            'full chart verification' => ['02_verify.sql', 'official_chart_exact_58'],
        ];
    }

    public function test_operational_identity_is_exam_board_not_exam_officer(): void
    {
        foreach (['00_preflight.sql', '01_apply.sql', '02_verify.sql'] as $file) {
            $sql = self::source('database/sql/p0-1/'.$file);
            self::assertStringContainsString('exam.board', $sql);
            self::assertStringNotContainsString('exam_officer', $sql);
        }
    }

    public function test_dual_role_landing_prefers_operational_role_and_student_ui_uses_safe_lookups(): void
    {
        $auth = self::source('../frontend/src/features/auth/auth.js');
        self::assertLessThan(strpos($auth, "hasRole('student'"), strpos($auth, "hasRole('registration_officer'"));
        $page = self::source('../frontend/src/features/student-dashboard/pages/StudentRegistration.jsx');
        self::assertStringContainsString('/academic-years/current', $page);
        self::assertStringContainsString('/semesters/active', $page);
    }

    public function test_login_and_me_share_the_identity_serializer(): void
    {
        $routes = self::source('routes/api.php');
        self::assertSame(2, substr_count($routes, '$identity->payload('));
        $identity = self::source('app/Services/UserIdentityService.php');
        foreach (['student_id', 'employee_id', 'roles', 'permissions', 'organizational_unit', 'access_scopes'] as $field) {
            self::assertStringContainsString("'$field'", $identity);
        }
    }
}
