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
            'empty employees supported' => ['01_apply.sql', "employee_number='P01-REGISTRAR'"],
            'permissions preserve custom grants' => ['01_apply.sql', 'Insert only missing required grants'],
            'presidency is seeded' => ['01_apply.sql', "'PRES','رئيس الجامعة'"],
            'administration 735' => ['01_apply.sql', "'735','إدارة الامتحانات'"],
            'correct 736 name' => ['01_apply.sql', "'736','مكتب التوثيق والتصديق'"],
            'scientific branch' => ['01_apply.sql', "'8','نائب رئيس الجامعة للشؤون العلمية'"],
            'community centers' => ['01_apply.sql', "'911','مركز التأهيل والتدريب'"],
            'last official office' => ['01_apply.sql', "'925','مكتب العدالة وحقوق الإنسان'"],
            'full chart verification' => ['02_verify.sql', 'organizational_units_exact_58'],
            'orphan scopes verified' => ['02_verify.sql', "'orphan_scopes'"],
            'duplicate identities verified' => ['02_verify.sql', "'duplicate_identity_links'"],
            'permissions verified' => ['02_verify.sql', "'required_role_permissions'"],
        ];
    }

    public function test_chart_contract_contains_exactly_58_rows_and_key_parents(): void
    {
        $verify = self::source('database/sql/p0-1/02_verify.sql');
        preg_match_all("/^\\('(?:PRES|[0-9]+)','[^']+','[^']+',(?:NULL|'[^']+')\\)[,;]$/mu", $verify, $rows);
        self::assertCount(58, $rows[0]);
        foreach ([
            "('PRES','رئيس الجامعة','presidency',NULL)",
            "('1','إدارة البحوث والدراسات','administration','PRES')",
            "('11','مركز البحوث والدراسات','center','1')",
            "('8','نائب رئيس الجامعة للشؤون العلمية','vice_presidency','PRES')",
            "('9','نائب رئيس الجامعة للشؤون المجتمعية','vice_presidency','PRES')",
            "('911','مركز التأهيل والتدريب','center','91')",
            "('925','مكتب العدالة وحقوق الإنسان','office','92')",
            "('22','مكتب الجودة والاعتماد الأكاديمي','office','2')",
            "('23','إدارة المشاريع الإنتاجية','administration','2')",
            "('736','مكتب التوثيق والتصديق','office','73')",
        ] as $row) {
            self::assertStringContainsString($row, $verify);
        }
    }

    public function test_apply_is_rerunnable_and_overall_covers_every_security_category(): void
    {
        $apply = self::source('database/sql/p0-1/01_apply.sql');
        self::assertGreaterThanOrEqual(58, substr_count($apply, 'ON DUPLICATE KEY UPDATE'));
        self::assertStringContainsString("NOT EXISTS(SELECT 1 FROM employees WHERE employee_number='P01-REGISTRAR')", $apply);

        $verify = self::source('database/sql/p0-1/02_verify.sql');
        $overall = substr($verify, strpos($verify, "SELECT 'OVERALL'"));
        foreach (['p01_expected_chart', 'p01_required_permissions', 'user_access_scopes', 'employee_id', 'student_id', 'user_roles'] as $category) {
            self::assertStringContainsString($category, $overall);
        }
    }

    public function test_reference_tables_are_global_read_only_resources_for_operational_staff(): void
    {
        $authorization = self::source('app/Services/ResourceAuthorizationService.php');
        self::assertStringContainsString("'academic_structure' => ['academic_', 'semesters'", $authorization);

        $scope = self::source('app/Services/DataScopeService.php');
        self::assertStringContainsString("['academic_levels', 'academic_years', 'semesters']", $scope);

        $routes = self::source('routes/api.php');
        foreach (['academic-levels', 'academic-years', 'semesters'] as $resource) {
            self::assertStringContainsString("Route::apiResource('$resource'", $routes);
        }
        self::assertGreaterThanOrEqual(3, substr_count($routes, "only(['index', 'show'])"));
        self::assertStringContainsString("':academic_structure.view'", $routes);
        self::assertStringContainsString("':academic_structure.manage'", $routes);

        $sql = self::source('database/sql/p0-1/01_apply.sql');
        self::assertStringContainsString("('registration_officer','academic_structure.view')", $sql);
        self::assertStringContainsString("('board_member','academic_structure.view')", $sql);
        self::assertStringNotContainsString("('registration_officer','academic_structure.manage')", $sql);
        self::assertStringNotContainsString("('board_member','academic_structure.manage')", $sql);
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
