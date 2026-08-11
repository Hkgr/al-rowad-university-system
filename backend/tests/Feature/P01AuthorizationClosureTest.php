<?php

namespace Tests\Feature;

use App\Models\AcademicLevel;
use App\Models\AcademicYear;
use App\Models\Semester;
use App\Models\User;
use App\Services\ResourceAuthorizationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

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
            'preflight final gate' => ['00_preflight.sql', ') can_apply;'],
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
            'exact full chart verification' => ['02_verify.sql', "'official_chart_58'"],
            'operational scope verification' => ['02_verify.sql', "'staff_university_scopes'"],
            'required access scope verification' => ['02_verify.sql', "'required_user_access_scopes'"],
            'duplicate verification' => ['02_verify.sql', "'duplicate_identity_links'"],
            'identity indexes' => ['02_verify.sql', "'identity_unique_indexes'"],
            'comprehensive overall' => ['02_verify.sql', "FROM p01_verification WHERE status='FAIL'"],
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
        $chart = require dirname(__DIR__, 2).'/database/seeders/data/p01_official_chart.php';
        $seeder = self::source('database/seeders/AuthorizationP01Seeder.php');
        self::assertCount(58, $chart);
        self::assertSame(['PRES', 'رئيس الجامعة', 'presidency', null], $chart[0]);
        self::assertContains(['1', 'إدارة البحوث والدراسات', 'administration', 'PRES'], $chart);
        self::assertContains(['8', 'نائب رئيس الجامعة للشؤون العلمية', 'vice_presidency', 'PRES'], $chart);
        self::assertContains(['9', 'نائب رئيس الجامعة للشؤون المجتمعية', 'vice_presidency', 'PRES'], $chart);
        self::assertContains(['11', 'مركز البحوث والدراسات', 'center', '1'], $chart);
        self::assertContains(['22', 'الجودة والاعتماد الأكاديمي', 'unit', '2'], $chart);
        self::assertContains(['23', 'مشاريع إنتاجية', 'unit', '2'], $chart);
        self::assertContains(['736', 'مكتب التوثيق والتصديق', 'office', '73'], $chart);
        self::assertContains(['911', 'مركز التأهيل والتدريب', 'center', '91'], $chart);
        self::assertContains(['925', 'مكتب العدالة وحقوق الإنسان', 'office', '92'], $chart);
        self::assertStringContainsString("require __DIR__.'/data/p01_official_chart.php'", $seeder);
        self::assertStringContainsString('count($chart) !== 58', $seeder);
        self::assertStringNotContainsString("whereNotIn('permission_id'", $seeder);
        self::assertStringContainsString("['exam.board', 'exam_officer'", $seeder);
    }

    public function test_apply_and_verify_embed_the_same_58_unit_contract(): void
    {
        $chart = require dirname(__DIR__, 2).'/database/seeders/data/p01_official_chart.php';
        $apply = self::source('database/sql/p0-1/01_apply.sql');
        $verify = self::source('database/sql/p0-1/02_verify.sql');
        foreach ($chart as [$code, $name, $type, $parent]) {
            $tuple = "'$code','$name','$type',".($parent === null ? 'NULL' : "'$parent'");
            self::assertStringContainsString($tuple, $verify, "Verify is missing official unit $code");
            foreach (["'$code'", "'$name'", "'$type'"] as $value) {
                self::assertStringContainsString($value, $apply, "Apply is missing contract value for $code");
            }
        }
    }

    public function test_checked_in_schema_fixture_matches_the_official_chart(): void
    {
        $chart = require dirname(__DIR__, 2).'/database/seeders/data/p01_official_chart.php';
        $schema = self::source('database/schema/al_rowad_university_db.sql');
        $start = strpos($schema, 'INSERT INTO `organizational_units`');
        $organizationalInsert = substr($schema, $start, strpos($schema, ';', $start) - $start);
        preg_match_all("/\\((\\d+), '([^']+)', '([^']+)', (\\d+), (NULL|\\d+), NULL, 1, '2026-05-24 12:41:57'/u", $organizationalInsert, $matches, PREG_SET_ORDER);
        $units = [];
        $codesById = [];
        foreach ($matches as $match) {
            $units[$match[2]] = ['id' => (int) $match[1], 'name' => $match[3], 'type_id' => (int) $match[4], 'parent_id' => $match[5] === 'NULL' ? null : (int) $match[5]];
            $codesById[(int) $match[1]] = $match[2];
        }
        $typeIds = ['presidency' => 3, 'vice_presidency' => 4, 'administration' => 5, 'directorate' => 6, 'office' => 7, 'center' => 8, 'club' => 9, 'college' => 10, 'institute' => 12, 'lab' => 13, 'unit' => 15];
        foreach ($chart as [$code, $name, $type, $parent]) {
            self::assertArrayHasKey($code, $units);
            self::assertSame($name, $units[$code]['name'], "Schema name mismatch for $code");
            self::assertSame($typeIds[$type], $units[$code]['type_id'], "Schema type mismatch for $code");
            $actualParent = $units[$code]['parent_id'] === null ? null : ($codesById[$units[$code]['parent_id']] ?? null);
            self::assertSame($parent, $actualParent, "Schema parent mismatch for $code");
        }
    }

    public function test_sql_preserves_custom_grants_and_first_apply_can_create_scope_table(): void
    {
        $apply = self::source('database/sql/p0-1/01_apply.sql');
        self::assertStringNotContainsString('DELETE rp FROM role_permissions', $apply);
        self::assertStringNotContainsString('operational user requires a manually reviewed valid scope', $apply);

        $preflight = self::source('database/sql/p0-1/00_preflight.sql');
        self::assertStringContainsString('@p01_scope_ok', $preflight);
        self::assertStringContainsString('identity_index_name_conflict', $preflight);
        self::assertStringContainsString('can_apply;', $preflight);

        $verify = self::source('database/sql/p0-1/02_verify.sql');
        self::assertStringContainsString("'required_role_permissions'", $verify);
        self::assertStringNotContainsString("'permission_matrix_exact'", $verify);
    }

    public function test_reference_reads_and_administrative_routes_use_separate_authorization_paths(): void
    {
        $routes = self::source('routes/api.php');
        self::assertStringContainsString("':academic_structure.view,registration.view,grades.view'", $routes);
        self::assertStringContainsString("Route::apiResource('academic-levels', AcademicLevelController::class)->only(['index', 'show'])", $routes);
        self::assertGreaterThanOrEqual(3, substr_count($routes, "':academic_structure.manage'"));

        $authorization = self::source('app/Services/ResourceAuthorizationService.php');
        foreach (['academic_levels', 'academic_years', 'semesters', 'registration.view', 'grades.view', 'academic_structure.manage'] as $value) {
            self::assertStringContainsString("'$value'", $authorization);
        }
        $scope = self::source('app/Services/DataScopeService.php');
        self::assertStringContainsString("['academic_levels', 'academic_years', 'semesters']", $scope);
    }

    #[DataProvider('academicReferenceReaderProvider')]
    public function test_global_academic_references_accept_each_intended_read_permission(string $permission): void
    {
        $user = $this->permissionUser([$permission]);
        $authorization = new ResourceAuthorizationService();
        foreach ([AcademicLevel::class, AcademicYear::class, Semester::class] as $model) {
            $authorization->authorize($user, $model, false);
        }

        self::addToAssertionCount(3);
    }

    public static function academicReferenceReaderProvider(): array
    {
        return [
            'registrar/structure reader' => ['academic_structure.view'],
            'registration/student reader' => ['registration.view'],
            'examination/grade reader' => ['grades.view'],
        ];
    }

    public function test_reference_read_does_not_grant_management_and_manage_permission_does(): void
    {
        $authorization = new ResourceAuthorizationService();
        try {
            $authorization->authorize($this->permissionUser(['registration.view']), AcademicYear::class, true);
            self::fail('Reference read permission unexpectedly authorized management.');
        } catch (AccessDeniedHttpException) {
            self::addToAssertionCount(1);
        }

        $authorization->authorize($this->permissionUser(['academic_structure.manage']), AcademicYear::class, true);
        self::addToAssertionCount(1);
    }

    public function test_unauthorized_identity_cannot_read_global_academic_references(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        (new ResourceAuthorizationService())->authorize($this->permissionUser([]), Semester::class, false);
    }

    public function test_exam_username_and_role_namespaces_are_not_confused(): void
    {
        $apply = self::source('database/sql/p0-1/01_apply.sql');
        self::assertStringContainsString("u.username='exam.board'", $apply);
        self::assertStringContainsString("WHEN 'exam.board' THEN 'exam_officer'", $apply);
        self::assertStringNotContainsString("('board_member','exams.manage')", $apply);
    }

    public function test_identity_linking_remains_super_admin_only(): void
    {
        $request = self::source('app/Http/Requests/User/LinkUserIdentityRequest.php');
        self::assertStringContainsString("effectiveRoles()->contains('super_admin')", $request);
        self::assertStringNotContainsString("hasPermission('users_permissions.manage')", $request);
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

    private function permissionUser(array $permissions): User
    {
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['hasPermission'])
            ->getMock();
        $user->method('hasPermission')->willReturnCallback(
            fn (string $permission): bool => in_array($permission, $permissions, true)
        );

        return $user;
    }
}
