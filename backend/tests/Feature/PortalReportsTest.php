<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ministry\MinistryQueries;
use App\Services\UserIdentityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MinistryReadFixture;
use Tests\TestCase;

final class PortalReportsTest extends TestCase
{
    use MinistryReadFixture;

    private const ROLE_MINISTRY = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
        Schema::table('employee_statuses', fn (Blueprint $t) => $t->string('status_name')->nullable());
        Schema::create('academic_plan_control', function (Blueprint $t) {
            $t->integer('control_id');
            $t->integer('schema_version');
            $t->boolean('is_ready');
        });
        DB::table('academic_plan_control')->insert(['control_id' => 1, 'schema_version' => 1, 'is_ready' => 1]);
        Schema::create('program_course_requirement_groups', function (Blueprint $t) {
            $t->increments('program_course_requirement_group_id');
            $t->integer('program_course_id');
            $t->integer('academic_requirement_group_id');
        });
        Schema::create('grade_components', function (Blueprint $t) {
            $t->increments('grade_component_id');
            $t->integer('course_offering_id');
            $t->string('component_type');
            $t->boolean('is_required');
        });
        Schema::create('grade_part_approvals', function (Blueprint $t) {
            $t->increments('grade_part_approval_id');
            $t->integer('course_offering_id');
            $t->string('component_type');
            $t->string('status');
        });
        foreach (['theoretical', 'practical'] as $part) {
            DB::table('grade_components')->insert(['course_offering_id' => 1, 'component_type' => $part, 'is_required' => 1]);
        }
        Schema::create('student_registration_requests', function (Blueprint $t) {
            $t->increments('student_registration_request_id');
            $t->integer('student_id');
            $t->integer('academic_year_id');
            $t->integer('semester_id');
            $t->string('status');
        });
        DB::table('student_registration_requests')->insert(['student_id' => 1, 'academic_year_id' => 10, 'semester_id' => 1, 'status' => 'approved']);
        Schema::create('user_activity_logs', function (Blueprint $t) {
            $t->increments('activity_log_id');
            $t->string('module_code');
            $t->string('action_code');
            $t->text('description');
            $t->timestamps();
        });
        DB::table('user_activity_logs')->insert(['module_code' => 'users_permissions', 'action_code' => 'account.created', 'description' => 'SECRET NOT FOR REPORTS', 'created_at' => now()]);
        Schema::table('student_course_registrations', fn (Blueprint $t) => $t->integer('result_status_id')->nullable());
        Schema::create('attendance_sessions', function (Blueprint $t) {
            $t->increments('attendance_session_id');
            $t->integer('course_offering_id');
            $t->string('session_type');
            $t->date('session_date');
        });
        Schema::create('attendance_statuses', function (Blueprint $t) {
            $t->increments('attendance_status_id');
            $t->string('status_code');
            $t->string('status_name');
        });
        Schema::create('student_attendance', function (Blueprint $t) {
            $t->increments('student_attendance_id');
            $t->integer('attendance_session_id');
            $t->integer('student_id');
            $t->integer('attendance_status_id');
        });
        DB::table('attendance_statuses')->insert(['attendance_status_id' => 1, 'status_code' => 'present', 'status_name' => 'حاضر']);
        DB::table('attendance_sessions')->insert(['attendance_session_id' => 1, 'course_offering_id' => 1, 'session_type' => 'theoretical', 'session_date' => '2024-10-15']);
        DB::table('student_attendance')->insert([['attendance_session_id' => 1, 'student_id' => 1, 'attendance_status_id' => 1], ['attendance_session_id' => 1, 'student_id' => 2, 'attendance_status_id' => 1]]);
        Schema::create('admission_applications', function (Blueprint $t) {
            $t->increments('admission_application_id');
            $t->integer('academic_program_id');
            $t->string('decision_status');
        });
        DB::table('admission_applications')->insert([['academic_program_id' => 1, 'decision_status' => 'pending'], ['academic_program_id' => 2, 'decision_status' => 'accepted']]);
    }

    private function actor(string $role, array $permissions, array $scope = ['college', 1], ?int $student = null): User
    {
        $roleId = DB::table('roles')->where('role_code', $role)->value('role_id') ?? DB::table('roles')->insertGetId(['role_code' => $role, 'role_name' => $role, 'is_active' => 1]);
        $id = DB::table('users')->insertGetId(['username' => 'synthetic-'.uniqid(), 'email' => uniqid().'@example.invalid', 'password_hash' => 'x', 'account_status_id' => 1, 'student_id' => $student]);
        DB::table('user_roles')->insert(['user_id' => $id, 'role_id' => $roleId, 'is_active' => 1]);
        foreach ($permissions as $p) {
            $pid = DB::table('permissions')->where('permission_code', $p)->value('permission_id') ?? DB::table('permissions')->insertGetId(['permission_code' => $p, 'permission_name' => $p, 'is_active' => 1]);
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $pid]);
        }
        if ($scope) {
            DB::table('user_access_scopes')->insert(['user_id' => $id, 'scope_type' => $scope[0], 'scope_id' => $scope[1], 'is_active' => 1]);
        }
        $u = User::findOrFail($id);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);

        return $u;
    }

    public function test_dean_scope_totals_categories_periods_and_no_write(): void
    {
        $this->actor('dean', ['students.view', 'courses.view', 'registration.view', 'grades.view']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach (['students', 'offerings', 'registrations', 'results'] as $r) {
            $d = $this->getJson('/api/v1/portal-reports/dean/'.$r.'?per_page=1')->assertOk()->json('data');
            $this->assertSame($d['total'], $d['meta']['total']);
            $this->assertLessThanOrEqual(1, count($d['rows']));
            foreach ($d['groups'] as $g) {
                $this->getJson('/api/v1/portal-reports/dean/'.$r.'?category='.urlencode($g['category']))->assertOk()->assertJsonPath('data.meta.total', $g['total']);
            }
        }
        foreach (DB::getQueryLog() as $q) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|alter)\b/i', $q['query']);
        }
        $this->getJson('/api/v1/portal-reports/dean/students?college_id=2')->assertForbidden();
        $this->getJson('/api/v1/portal-reports/dean/offerings?semester_id=1')->assertUnprocessable();
        $this->getJson('/api/v1/portal-reports/dean/students?academic_year_id=10')->assertUnprocessable();
        $this->getJson('/api/v1/portal-reports/dean/offerings?academic_year_id=10&semester_id=1')->assertOk();
    }

    public function test_denials_role_permission_scope_identity_and_read_only(): void
    {
        $this->getJson('/api/v1/portal-reports/dean')->assertUnauthorized();
        $this->actor('super_admin', [], ['university', 91]);
        $this->getJson('/api/v1/portal-reports/dean')->assertForbidden();
        $u = $this->actor('dean', ['students.view'], []);
        $this->getJson('/api/v1/portal-reports/dean')->assertForbidden();
        DB::table('user_access_scopes')->insert(['user_id' => $u->user_id, 'scope_type' => 'college', 'scope_id' => 1, 'is_active' => 1]);
        $this->getJson('/api/v1/portal-reports/dean')->assertOk();
        $this->getJson('/api/v1/portal-reports/dean/results')->assertForbidden();
        $this->postJson('/api/v1/portal-reports/dean/students')->assertStatus(405);
        $this->getJson('/api/v1/portal-reports/dean/students?student_id=2')->assertUnprocessable();
    }

    public function test_parts_missing_approval_are_distinct_drafts_and_results_require_latest_approval(): void
    {
        $this->actor('exam_officer', ['exams.manage', 'grades.view'], ['university', 91]);
        $d = $this->getJson('/api/v1/portal-reports/exam-board/parts?per_page=1')->assertOk()->json('data');
        $this->assertSame(2, $d['total']);
        $this->assertSame('draft', $d['rows'][0]['category']);
        $next = $this->getJson('/api/v1/portal-reports/exam-board/parts?per_page=1&page=2')->assertOk()->json('data.rows.0');
        $this->assertNotSame($d['rows'][0]['component_type'], $next['component_type']);
        $q = MinistryQueries::officialResults();
        $expected = $q->count();
        $this->getJson('/api/v1/portal-reports/exam-board/results')->assertOk()->assertJsonPath('data.total', $expected);
        DB::table('grade_approvals')->update(['approval_status_id' => 4]);
        $this->getJson('/api/v1/portal-reports/exam-board/results')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_student_term_registration_is_own_only_and_optional_schema_is_unavailable(): void
    {
        $this->actor('student', ['registration.view', 'attendance.view'], [], 1);
        $d = $this->getJson('/api/v1/portal-reports/student/registrations')->assertOk()->json('data');
        $expected = DB::table('student_course_registrations')->where('student_id', 1)->where('registration_status_id', 1)->count();
        $this->assertSame($expected, $d['total']);
        $this->getJson('/api/v1/portal-reports/student/attendance')->assertOk()->assertJsonPath('data.total', 1);
        Schema::drop('student_attendance');
        $this->getJson('/api/v1/portal-reports/student/attendance')->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.total', null);
        $this->getJson('/api/v1/portal-reports/student/registrations?student_id=4')->assertUnprocessable();
        $this->getJson('/api/v1/portal-reports/student/registrations?college_id=1')->assertUnprocessable();
    }

    public function test_ministry_namespace_and_technical_activity_privacy(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::findOrFail(2));
        $this->getJson('/api/v1/ministry/reports')->assertOk();
        $this->getJson('/api/v1/ministry/reports/students')->assertOk();
        $this->getJson('/api/v1/portal-reports/dean/students')->assertForbidden();
        $this->actor('technical_team', ['technical_portal.access', 'system_activity.view', 'user_accounts.view'], []);
        $json = $this->getJson('/api/v1/portal-reports/technical/activity')->assertOk()->getContent();
        $this->assertStringNotContainsString('SECRET', $json);
        $this->assertStringNotContainsString('description', $json);
        $this->getJson('/api/v1/portal-reports/technical/accounts')->assertOk();
    }

    public function test_each_remaining_portal_has_real_read_projection_and_missing_permission_denial(): void
    {
        foreach ([
            ['hr', 'hr_officer', ['hr.view'], 'employees'],
            ['academic-structure', 'academic_structure_officer', ['academic_structure.view'], 'programs'],
            ['student-affairs', 'registration_officer', ['students.view', 'registration_requests.view', 'graduation_decisions.view'], 'requests'],
            ['admissions', 'registration_officer', ['registration.view'], 'registrations'],
            ['president', 'university_president', ['president_portal.access', 'president_portal.reports.view', 'president_portal.students.view'], 'students'],
        ] as [$portal,$role,$permissions,$report]) {
            $u = $this->actor($role, $permissions, ['university', 91]);
            $this->getJson('/api/v1/portal-reports/'.$portal)->assertOk();
            $this->getJson('/api/v1/portal-reports/'.$portal.'/'.$report)->assertOk();
            DB::table('user_access_scopes')->where('user_id', $u->user_id)->update(['is_active' => 0]);
            $this->getJson('/api/v1/portal-reports/'.$portal.'/'.$report)->assertForbidden();
        }
    }

    public function test_professor_cannot_use_university_scope_to_read_unassigned_offerings(): void
    {
        $u = $this->actor('doctor_instructor', ['grades.manage'], ['university', 91]);
        DB::table('users')->where('user_id', $u->user_id)->update(['employee_id' => 2]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::findOrFail($u->user_id));
        DB::table('course_offering_instructors')->insert(['course_offering_id' => 5, 'faculty_member_id' => 2, 'instructor_role' => 'theoretical', 'is_active' => 1]);
        $this->getJson('/api/v1/portal-reports/professor/offerings')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.rows.0.id', 5);
        $this->getJson('/api/v1/portal-reports/professor/registrations')->assertOk();
    }

    public function test_personal_report_runs_canonical_snapshot_and_trends_do_not_require_a_current_year(): void
    {
        $this->actor('student', ['grades.view'], [], 1);
        $this->getJson('/api/v1/portal-reports/student/academic')->assertOk()->assertJsonStructure(['data' => ['academic' => ['transcript', 'requirements', 'generation']]]);
        $this->actor('university_president', ['president_portal.access', 'president_portal.reports.view'], ['university', 91]);
        DB::table('academic_years')->update(['is_current' => 0]);
        $this->getJson('/api/v1/portal-reports/president/trends')->assertOk()->assertJsonStructure(['data' => ['trends' => ['intake_by_year', 'graduates_by_year', 'official_results_by_term']]]);
    }

    public function test_available_projections_empty_is_zero_and_query_count_does_not_grow_with_rows(): void
    {
        $this->actor('registration_officer', ['admissions.view', 'registration_requests.view'], ['college', 1]);
        $this->getJson('/api/v1/portal-reports/admissions/admissions')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.rows.0.category', 'pending');
        $this->actor('exam_officer', ['exams.manage'], ['university', 91]);
        $this->getJson('/api/v1/portal-reports/exam-board/deprivation')->assertOk()->assertJsonPath('data.available', true)->assertJsonPath('data.total', 0);
        $this->actor('dean', ['students.view'], ['college', 1]);
        $this->getJson('/api/v1/portal-reports/dean/students')->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/portal-reports/dean/students')->assertOk();
        $before = count(DB::getQueryLog());
        $source = (array) DB::table('students')->where('student_id', 1)->first();
        foreach (range(100, 180) as $id) {
            DB::table('students')->insert(array_replace($source, ['student_id' => $id, 'student_number' => 'SYN-'.$id]));
        }
        DB::flushQueryLog();
        $d = $this->getJson('/api/v1/portal-reports/dean/students')->assertOk()->json('data');
        $this->assertSame($before, count(DB::getQueryLog()));
        $this->assertCount(25, $d['rows']);
        $this->assertGreaterThan(80, $d['total']);
    }

    public function test_all_authorized_reports_and_optional_live_browser_database(): void
    {
        $actors = [];
        foreach ([
            ['dean', 'dean', ['students.view', 'teaching_staff.view', 'courses.view', 'registration.view', 'grades.view'], ['college', 1], null],
            ['student-affairs', 'registration_officer', ['students.view', 'registration.view', 'registration_requests.view', 'academic_progression.view', 'graduation_decisions.view'], ['college', 1], null],
            ['admissions', 'registration_officer', ['admissions.view', 'registration.view', 'registration_requests.view'], ['college', 1], null],
            ['exam-board', 'exam_officer', ['exams.manage', 'grades.view', 'courses.view', 'supplementary_exams.registrations.view'], ['university', 91], null],
            ['student', 'student', ['grades.view', 'registration.view', 'attendance.view'], [], 1],
            ['hr', 'hr_officer', ['hr.view'], ['university', 91], null],
            ['academic-structure', 'academic_structure_officer', ['academic_structure.view'], ['university', 91], null],
            ['technical', 'technical_team', ['technical_portal.access', 'user_accounts.view', 'system_activity.view'], [], null],
            ['president', 'university_president', ['president_portal.access', ...array_map(fn ($s) => 'president_portal.'.$s.'.view', ['reports', 'students', 'colleges', 'exams','staff'])], ['university', 91], null],
            ['professor', 'doctor_instructor', ['grades.manage', 'attendance.manage'], [], null],
        ] as [$portal,$role,$permissions,$scope,$student]) {
            $u = $this->actor($role, $permissions, $scope, $student);
            if ($portal === 'professor') {
                DB::table('users')->where('user_id', $u->user_id)->update(['employee_id' => 2]);
                $u = User::findOrFail($u->user_id);
                Sanctum::actingAs($u);
            }
            $actors[$portal] = $u;
            foreach ($this->getJson('/api/v1/portal-reports/'.$portal)->assertOk()->json('data.reports') as $definition) {
                $this->getJson('/api/v1/portal-reports/'.$portal.'/'.$definition['id'])->assertOk();
            }
        }
        $actors['ministry'] = User::findOrFail(2);
        if ($directory = getenv('PORTAL_REPORT_BROWSER_DIR')) {
            // Test-only opt-in export. Never opens/imports any production database or dump.
            $directory = realpath($directory);
            $temp = realpath(sys_get_temp_dir());
            $this->assertNotFalse($directory);
            $this->assertStringStartsWith(strtolower($temp).DIRECTORY_SEPARATOR, strtolower($directory).DIRECTORY_SEPARATOR);
            $this->assertSame(':memory:', config('database.connections.sqlite.database'));
            Schema::create('personal_access_tokens', function (Blueprint $t) {
                $t->id();
                $t->string('tokenable_type');
                $t->unsignedBigInteger('tokenable_id');
                $t->string('name');
                $t->string('token', 64)->unique();
                $t->text('abilities')->nullable();
                $t->timestamp('last_used_at')->nullable();
                $t->timestamp('expires_at')->nullable();
                $t->timestamps();
            });
            $identities = [];
            foreach ($actors as $portal => $u) {
                $identities[$portal] = ['identity' => app(UserIdentityService::class)->payload($u), 'token' => $u->createToken('isolated-local-report-test')->plainTextToken];
            }
            file_put_contents($directory.'/identities.json',json_encode($identities,JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            DB::statement('VACUUM INTO ?',[$directory.'/reports.sqlite']);
        }
    }
}
