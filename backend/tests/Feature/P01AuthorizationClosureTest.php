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
            'preflight excess/missing' => ['00_preflight.sql', "'EXCESS'"],
            'case-sensitive report' => ['00_preflight.sql', 'BINARY s.email=BINARY u.email'],
            'idempotent table' => ['01_apply.sql', 'CREATE TABLE IF NOT EXISTS user_access_scopes'],
            'idempotent chart root' => ['01_apply.sql', "SELECT 'PRES','رئيس الجامعة'"],
            'registrar identity' => ['01_apply.sql', "'P01-REGISTRAR'"],
            'exam identity' => ['01_apply.sql', "'P01-EXAM-OFFICER'"],
            'existing exam username' => ['01_apply.sql', "u.username='exam.board'"],
            'required grants are additive' => ['01_apply.sql', 'INSERT INTO role_permissions'],
            'student cannot manage registration' => ['01_apply.sql', "('student','registration.view')"],
            'administration 735' => ['01_apply.sql', "'735','إدارة الامتحانات','administration'"],
            'certification office 736' => ['01_apply.sql', "'736','مكتب التوثيق والتصديق','office'"],
            'exact full chart verification' => ['02_verify.sql', "'official_chart_exact'"],
            'operational scope verification' => ['02_verify.sql', "r.role_code IN ('exam_officer','registration_officer')"],
            'duplicate verification' => ['02_verify.sql', 'HAVING duplicates>1'],
        ];
    }

    public function test_staff_only_resources_do_not_fall_back_to_student_identity_scope(): void
    {
        $creditLimits = self::source('app/Http/Controllers/Api/StudentCreditLimitController.php');
        self::assertStringContainsString('assertStaffRegistrar', $creditLimits);
        self::assertStringContainsString('scopeStudentsForStaff', $creditLimits);

        $auditLogs = self::source('app/Http/Controllers/Api/GradeAuditLogController.php');
        self::assertStringContainsString('assertStaffGrader', $auditLogs);
        self::assertStringContainsString('scopeRegistrationsForStaff', $auditLogs);

        $studentPolicy = self::source('app/Policies/StudentPolicy.php');
        self::assertStringContainsString('isStaff($user)', $studentPolicy);
        self::assertStringContainsString('canStaffAccessStudent', $studentPolicy);
    }

    public function test_development_seeder_uses_the_same_complete_official_chart(): void
    {
        $seeder = self::source('database/seeders/AuthorizationP01Seeder.php');
        foreach ([
            "['PRES', 'رئيس الجامعة', 'presidency', null]",
            "['7', 'نائب رئيس الجامعة للشؤون الإدارية', 'vice_presidency', 'PRES']",
            "['71', 'مديرية الشؤون الإدارية', 'directorate', '7']",
            "['72', 'مديرية الشؤون المالية', 'directorate', '7']",
            "['73', 'مديرية شؤون الطلاب', 'directorate', '7']",
            "['731', 'مكتب الإرشاد والتوجيه', 'office', '73']",
            "['732', 'مكتب القبول والتسجيل', 'office', '73']",
            "['733', 'مكتب الخدمات الطلابية', 'office', '73']",
            "['734', 'مكتب المنح والإيفاد والتبادل الطلابي', 'office', '73']",
            "['735', 'إدارة الامتحانات', 'administration', '73']",
            "['736', 'مكتب التوثيق والتصديق', 'office', '73']",
        ] as $unit) {
            self::assertStringContainsString($unit, $seeder);
        }
    }

    public function test_sql_preserves_custom_grants_and_first_apply_can_create_scope_table(): void
    {
        $apply = self::source('database/sql/p0-1/01_apply.sql');
        self::assertStringNotContainsString('DELETE rp FROM role_permissions', $apply);
        self::assertStringNotContainsString('operational user requires a manually reviewed valid scope', $apply);

        $preflight = self::source('database/sql/p0-1/00_preflight.sql');
        self::assertStringContainsString("'SELECT 1 can_apply, ''scope table will be created by apply", $preflight);

        $verify = self::source('database/sql/p0-1/02_verify.sql');
        self::assertStringContainsString("'required_permission_grants'", $verify);
        self::assertStringNotContainsString("'permission_matrix_exact'", $verify);
    }

    public function test_reference_reads_and_administrative_routes_use_separate_authorization_paths(): void
    {
        $routes = self::source('routes/api.php');
        self::assertStringContainsString("':academic_structure.view,registration.view,grades.view'", $routes);
        self::assertStringContainsString("Route::apiResource('academic-levels', AcademicLevelController::class)->only(['index', 'show'])", $routes);
        self::assertGreaterThanOrEqual(7, substr_count($routes, 'RequireSystemAdministrator::class'));
    }

    public function test_dual_role_landing_prefers_operational_role_and_student_ui_uses_safe_lookups(): void
    {
        $auth = self::source('../frontend/src/features/auth/auth.js');
        self::assertLessThan(strpos($auth, "hasRole('student'"), strpos($auth, "hasRole('registration_officer'"));
        $page = self::source('../frontend/src/features/student-dashboard/pages/StudentRegistration.jsx');
        self::assertStringContainsString('/academic-years/current', $page);
        self::assertStringContainsString('/semesters/active', $page);
        self::assertStringContainsString('s.data?.data ?? s.data', $page);
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
