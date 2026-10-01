<?php

namespace Tests\Feature;

use App\Models\{Student, UniversityEmailOperation, User};
use App\Services\DataScopeService;
use App\Support\{AdministrativeGovernance, ExecutiveReportAccess, ExamManualGradeEntryAccess, MinistryPlacementAccess, MinistryPortal, PresidentPortal, ScientificCourseAccess, ScientificProgramAccess};
use Illuminate\Support\Facades\{DB, Http};
use Laravel\Sanctum\Sanctum;

/** Reuses the real HTTP/middleware fixture and fake Mailcow transport, including all Phase 2 regressions. */
class SuperAdminEmailCreateTest extends UniversityEmailPhase2Test
{
    private const CREATE = '/api/v1/technical/university-email/students/1/create';

    private function createMailbox(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(self::CREATE, ['english_first_name' => 'Ahmad', 'confirmed' => true]);
    }

    public function test_active_admin_without_assigned_permissions_roles_or_scopes_can_search_show_and_create(): void
    {
        $admin = User::findOrFail(1); Sanctum::actingAs($admin);
        $this->assertSame('active', $admin->accountStatus->status_code);
        $this->assertSame(['super_admin'], $admin->effectiveRoles()->all());
        $this->assertSame([], $admin->effectivePermissions()->all());
        $this->assertSame(0, DB::table('user_access_scopes')->where('user_id', $admin->user_id)->count());
        $this->assertSame([], app(DataScopeService::class)->scopes($admin));
        $this->assertFalse(app(DataScopeService::class)->hasActualUniversityScope($admin));
        $total = Student::count();
        $this->assertGreaterThan(1, $total);
        foreach (['', '?q=', '?q=%20%20'] as $query) {
            $this->getJson('/api/v1/technical/university-email/students'.$query)->assertOk()
                ->assertJsonPath('meta.total', $total)->assertJsonCount($total, 'data');
        }
        foreach (['أحمد', 'R24011002'] as $query) {
            $this->getJson('/api/v1/technical/university-email/students?q='.urlencode($query))->assertOk()
                ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.student_id', 1)
                ->assertJsonPath('data.0.student_number', 'R24011002');
        }
        $first = $this->getJson('/api/v1/technical/university-email/students?per_page=1&page=1')->assertOk()
            ->assertJsonPath('meta.total', $total)->assertJsonCount(1, 'data')->json('data.0.student_id');
        $second = $this->getJson('/api/v1/technical/university-email/students?per_page=1&page=2')->assertOk()
            ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.total', $total)->assertJsonCount(1, 'data')->json('data.0.student_id');
        $this->assertNotSame($first, $second);
        $this->getJson('/api/v1/technical/university-email/students/1')->assertOk()->assertJsonPath('data.student.student_id', 1);
        $result = $this->createMailbox()->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.provisioning_status', 'created')->assertJsonPath('data.operations.0.status', 'confirmed')->json('data');
        $this->assertSame(24, strlen($result['credentials']['password']));
        $this->assertSame(1, $this->writes);
        $this->assertSame(1, UniversityEmailOperation::count());
        foreach (['student_university_emails', 'university_email_operations', 'user_activity_logs', 'university_email_receipts'] as $table) {
            $this->assertStringNotContainsString($result['credentials']['password'], json_encode(DB::table($table)->get()));
        }
        $state = $this->getJson('/api/v1/technical/university-email/students/1/provisioning')->assertOk()->json();
        $this->assertStringNotContainsString($result['credentials']['password'], json_encode($state));
        $this->postJson('/api/v1/technical/university-email/students/1/provisioning/receipt',
            ['operation_id' => $result['credentials']['operation_id'], 'generation' => $result['credentials']['generation']])->assertOk();
        $this->assertDatabaseHas('student_university_emails', ['student_id' => 1, 'handover_status' => 'not_delivered']);
        $this->createMailbox()->assertConflict()->assertJsonPath('error_code', 'university_email_operation_requires_review');
        $this->assertSame(1, $this->writes);
    }

    public function test_administrative_portals_share_authority_without_fabricating_personal_identity(): void
    {
        $admin = User::findOrFail(1); Sanctum::actingAs($admin);
        $this->getJson('/api/v1/vice-presidency/reports/definitions')->assertOk();
        $this->getJson('/api/v1/ministry/colleges')->assertOk();
        foreach (['dean', 'president', 'student-affairs', 'admissions', 'exam-board', 'professor', 'hr', 'academic-structure', 'technical'] as $portal) {
            $this->getJson('/api/v1/portal-reports/'.$portal)->assertOk();
        }
        $this->assertTrue(app(PresidentPortal::class)->allows($admin, 'dashboard'));
        $this->assertTrue(app(MinistryPortal::class)->allows($admin, MinistryPortal::DASHBOARD));
        $this->assertTrue(app(ExecutiveReportAccess::class)->allows($admin));
        $this->assertTrue(app(AdministrativeGovernance::class)->allows($admin, AdministrativeGovernance::DEANS_MANAGE));
        $this->assertTrue(app(MinistryPlacementAccess::class)->canManage($admin));
        app(ScientificCourseAccess::class)->authorize($admin, true);
        app(ScientificProgramAccess::class)->authorize($admin, ScientificProgramAccess::APPROVE);
        app(ExamManualGradeEntryAccess::class)->authorize($admin, Student::findOrFail(1));
        $this->assertTrue(app(DataScopeService::class)->canMutateStudent($admin, Student::findOrFail(1)));
        $legacy = Student::findOrFail(1);
        $legacy->academic_program_id = null;
        $this->assertTrue(app(DataScopeService::class)->canMutateStudent($admin, $legacy));
        $this->assertFalse(app(DataScopeService::class)->canMutateStudent($admin, new Student()));
        $faculty = \App\Models\FacultyMember::firstOrFail();
        $this->assertTrue(app(DataScopeService::class)->canMutateFacultyMember($admin, $faculty));
        $this->assertTrue($admin->isDean()); $this->assertTrue($admin->isExamOfficer());
        $this->assertFalse($admin->hasRoleCode('dean'));
        $this->assertNull($admin->student_id); $this->assertNull($admin->employee_id);
        $this->assertFalse($admin->isStudent()); $this->assertFalse($admin->isProfessor());
        foreach (['/student/university-email', '/portal-reports/student', '/student/registration', '/student/transcript', '/student/academic-record'] as $selfPath) {
            $this->getJson('/api/v1'.$selfPath)->assertForbidden();
        }
        $this->getJson('/api/v1/professor/course-offerings')->assertOk()
            ->assertJsonPath('data.faculty_member', null)->assertJsonCount(0, 'data.offerings');
        $this->assertSame([], app(\App\Services\ProfessorGradeAssignmentService::class)->assignedGradeParts($admin, 1));
        $this->assertDatabaseCount('user_roles', 8);
    }

    public function test_admin_authority_never_enables_disabled_mailcow_provisioning(): void
    {
        Sanctum::actingAs(User::findOrFail(1));
        foreach (['mailcow.provisioning_enabled' => false, 'mailcow.contract_verified' => false, 'mailcow.write_api_key' => ''] as $key => $disabled) {
            $configured = config($key); config([$key => $disabled]);
            $this->getJson('/api/v1/technical/university-email/students/1/provisioning')->assertOk()->assertJsonPath('data.enabled', false);
            $this->createMailbox()->assertStatus(503)->assertJsonPath('error_code', 'university_email_provisioning_disabled');
            $this->assertDatabaseCount('student_university_emails', 0);
            $this->assertDatabaseCount('university_email_operations', 0);
            $this->assertDatabaseCount('user_activity_logs', 0);
            config([$key => $configured]);
        }
        $this->assertSame(0, $this->writes);
        Http::assertNothingSent();
    }

    public function test_ordinary_technical_permissions_and_actual_scopes_remain_required(): void
    {
        $technical = User::findOrFail(8); $this->assertFalse($technical->isSuperAdmin());
        DB::table('user_access_scopes')->where('user_id', 8)->delete();
        $this->getJson('/api/v1/technical/university-email/students')->assertOk()->assertJsonPath('meta.total', 0);
        $this->createMailbox()->assertForbidden();
        DB::table('user_access_scopes')->insert(['user_id' => 8, 'scope_type' => 'college', 'scope_id' => 1, 'is_active' => true]);
        $outside = Student::whereHas('academicProgram.department', fn ($q) => $q->where('college_id', 2))->firstOrFail();
        $this->getJson('/api/v1/technical/university-email/students/'.$outside->student_id)->assertForbidden();
        foreach (['university_email.manage', 'university_email.provision', 'university_email.issue_receipt'] as $code) {
            $id = DB::table('permissions')->where('permission_code', $code)->value('permission_id');
            DB::table('permissions')->where('permission_id', $id)->update(['is_active' => false]);
            $this->createMailbox()->assertForbidden();
            DB::table('permissions')->where('permission_id', $id)->update(['is_active' => true]);
        }
        $this->assertSame(0, $this->writes); $this->assertDatabaseCount('student_university_emails', 0);
        foreach ([2, 3, 4, 6] as $id) { Sanctum::actingAs(User::findOrFail($id)); $this->createMailbox()->assertForbidden(); }
        DB::table('users')->where('user_id', 1)->update(['account_status_id' => 2]);
        Sanctum::actingAs(User::findOrFail(1)); $this->createMailbox()->assertForbidden();
    }

    public function test_one_step_is_strict_and_does_not_repeat_prewrite_or_uncertain_operations(): void
    {
        $this->postJson(self::CREATE, ['english_first_name' => 'Ahmad', 'confirmed' => false])->assertUnprocessable();
        $this->postJson(self::CREATE, ['english_first_name' => 'Ahmad', 'confirmed' => true, 'password' => 'untrusted'])->assertUnprocessable();
        $this->assertDatabaseCount('student_university_emails', 0);
        $this->aliases = [['address' => 'ahmad.r24011002@alrowaduni.edu.sy']];
        $this->createMailbox()->assertConflict();
        $this->createMailbox()->assertConflict()->assertJsonPath('error_code', 'university_email_operation_requires_review');
        $this->assertSame(0, $this->writes);
        $op = UniversityEmailOperation::firstOrFail();
        $this->postJson('/api/v1/technical/university-email/students/1/provisioning/cancel',
            ['operation_id' => $op->operation_id, 'generation' => $op->generation, 'confirmed' => true])->assertOk();
        $this->aliases = []; $this->failure = 'lost_response';
        $response = $this->createMailbox()->assertConflict()->assertJsonPath('error_code', 'university_email_remote_uncertain');
        $this->assertNull($response->json('data.credentials'));
        $this->assertSame('uncertain', UniversityEmailOperation::where('status', '!=', 'cancelled')->firstOrFail()->status);
        $this->createMailbox()->assertConflict(); $this->assertSame(1, $this->writes);
        $this->failure = null;
        $op = UniversityEmailOperation::where('status', 'uncertain')->firstOrFail();
        // Respect the canonical in-flight grace period before read-only reconciliation.
        DB::table('university_email_operations')->where('operation_id', $op->operation_id)->update(['updated_at' => now()->subMinutes(2)]);
        $this->postJson('/api/v1/technical/university-email/students/1/provisioning/reconcile', ['operation_id' => $op->operation_id])->assertOk();
        $this->assertSame(1, $this->writes);
        $this->assertDatabaseHas('student_university_emails', ['student_id' => 1, 'provisioning_status' => 'created', 'credential_operation_id' => null]);
    }

    public function test_one_step_prewrite_timeout_never_posts_or_returns_credentials(): void
    {
        $this->failure = 'preflight_timeout';
        $response = $this->createMailbox()->assertConflict();
        $this->assertNull($response->json('data.credentials'));
        $this->assertSame(0, $this->writes);
        $this->assertDatabaseHas('university_email_operations', ['write_started_at' => null]);
        $this->createMailbox()->assertConflict()->assertJsonPath('error_code', 'university_email_operation_requires_review');
        $this->assertSame(0, $this->writes);
    }

    public function test_one_step_remote_mailbox_conflict_is_not_taken_over(): void
    {
        $this->boxes['ahmad.r24011002@alrowaduni.edu.sy'] = ['username' => 'ahmad.r24011002@alrowaduni.edu.sy',
            'domain' => 'alrowaduni.edu.sy', 'quota' => 50 * 1048576, 'active_int' => 1, 'attributes' => [], 'tags' => []];
        $this->createMailbox()->assertConflict()->assertJsonPath('error_code', 'university_email_address_conflict');
        $this->assertSame(0, $this->writes);
        $this->assertDatabaseHas('student_university_emails', ['provisioning_status' => 'draft']);
    }

    public function test_one_step_local_confirmation_failure_does_not_repeat_remote_creation(): void
    {
        DB::statement("CREATE TRIGGER synthetic_audit_failure BEFORE INSERT ON user_activity_logs WHEN NEW.action_code = 'university_email.create_confirmed' BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        $response = $this->createMailbox()->assertConflict()->assertJsonPath('error_code', 'university_email_local_confirmation_failed');
        $this->assertNull($response->json('data.credentials'));
        $this->assertSame(1, $this->writes);
        $this->createMailbox()->assertConflict();
        $this->assertSame(1, $this->writes);
        $this->assertDatabaseCount('university_email_receipts', 0);
    }

    public function test_one_step_address_uniqueness_rolls_back_draft_operation_and_audit(): void
    {
        $this->createMailbox()->assertOk();
        DB::table('students')->where('student_id', 2)->update(['student_number' => 'R24011002']);
        $before = DB::table('user_activity_logs')->count();
        $this->postJson('/api/v1/technical/university-email/students/2/create', ['english_first_name' => 'Ahmad', 'confirmed' => true])->assertConflict();
        $this->assertDatabaseCount('student_university_emails', 1);
        $this->assertDatabaseCount('university_email_operations', 1);
        $this->assertSame($before, DB::table('user_activity_logs')->count());
        $this->assertSame(1, $this->writes);
    }
}
