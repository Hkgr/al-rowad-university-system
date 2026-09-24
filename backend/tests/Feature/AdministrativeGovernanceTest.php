<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AdministrativeGovernanceSchema;
use Tests\TestCase;

/**
 * Real HTTP + SQLite persistence for the administrative VP governance API:
 * teacher profiles / college affiliation and college deans. The schema is a copy of
 * the production tables; all data below is synthetic.
 */
final class AdministrativeGovernanceTest extends TestCase
{
    use AdministrativeGovernanceSchema;

    private const API = '/api/v1/vice-presidency/administrative';

    private const SUPER = 1;
    private const VP = 2;
    private const VP_NO_SCOPE = 3;
    private const NOBODY = 5;
    private const DEAN_A = 6;
    private const MULTI_ROLE_VP = 7;
    private const CANDIDATE = 8;
    private const FOREIGN = 9;
    private const DISABLED_VP = 11;
    private const DEAN_OTHER_UNIVERSITY = 12;

    private const COLLEGE_A = 1;
    private const COLLEGE_B = 2;
    private const COLLEGE_NO_UNIT = 3;
    private const UNIT_A = 10;
    private const UNIT_B = 11;

    private const ROLE_SUPER = 1;
    private const ROLE_VP = 2;
    private const ROLE_DEAN = 3;
    private const ROLE_INSTRUCTOR = 5;
    private const ROLE_EXAM = 6;

    private const STRONG_PASSWORD = 'Strong-Pass-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAdministrativeGovernanceSchema();
        $this->seedAdministrativeGovernanceFixture();
    }

    // ── Access matrix ────────────────────────────────────────────────────────

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson(self::API.'/faculty')->assertUnauthorized();
        $this->getJson(self::API.'/deans')->assertUnauthorized();
        $this->getJson(self::API.'/dashboard')->assertUnauthorized();
    }

    public function test_users_without_the_full_access_pair_are_forbidden_everywhere(): void
    {
        foreach ([self::NOBODY, self::DEAN_A, self::VP_NO_SCOPE, self::DISABLED_VP] as $userId) {
            $this->actingAsUser($userId);
            foreach (['/faculty', '/deans', '/dashboard', '/faculty/employee-lookup?employee_number=E-20', '/deans/account-lookup?username=x'] as $path) {
                // A disabled account is stopped earlier by EnsureActiveAccount (generic code).
                $this->getJson(self::API.$path)->assertForbidden()
                    ->assertJsonPath('error_code', $userId === self::DISABLED_VP ? 'forbidden' : 'administrative_governance_forbidden');
            }
            $this->postJson(self::API.'/faculty', $this->newTeacher())->assertForbidden();
            $this->postJson(self::API.'/deans', $this->appointNew())->assertForbidden();
            $this->postJson(self::API.'/deans/'.self::COLLEGE_A.'/end', ['dean_user_id' => self::DEAN_A])->assertForbidden();
        }
        self::assertSame(0, DB::table('faculty_members')->where('employee_id', '>', 100)->count());
        $this->assertDeanScopes(self::DEAN_A, [self::COLLEGE_A]);
    }

    public function test_view_only_vp_can_read_but_not_change(): void
    {
        DB::table('role_permissions')->where('role_id', self::ROLE_VP)->whereIn('permission_id', [2, 4])->delete();
        $this->actingAsUser(self::VP);

        $this->getJson(self::API.'/faculty')->assertOk()
            ->assertJsonPath('data.capabilities', ['faculty_manage' => false, 'deans_manage' => false]);
        $this->getJson(self::API.'/deans')->assertOk();
        $this->postJson(self::API.'/faculty', $this->newTeacher())->assertForbidden();
        $this->patchJson(self::API.'/faculty/1', ['specialization' => 'x'])->assertForbidden();
        $this->postJson(self::API.'/faculty/1/affiliation', ['mode' => 'assign', 'college_id' => self::COLLEGE_B])->assertForbidden();
        $this->postJson(self::API.'/deans', $this->appointNew())->assertForbidden();
        $this->postJson(self::API.'/deans/'.self::COLLEGE_A.'/end', ['dean_user_id' => self::DEAN_A])->assertForbidden();
        $this->assertDeanScopes(self::DEAN_A, [self::COLLEGE_A]);
    }

    public function test_authorized_vp_multi_role_vp_and_super_admin_with_university_scope_are_allowed(): void
    {
        foreach ([self::VP, self::MULTI_ROLE_VP, self::SUPER] as $userId) {
            $this->actingAsUser($userId);
            $this->getJson(self::API.'/faculty')->assertOk()
                ->assertJsonPath('data.capabilities', ['faculty_manage' => true, 'deans_manage' => true]);
            $this->getJson(self::API.'/deans')->assertOk();
            $this->getJson(self::API.'/dashboard')->assertOk();
        }
    }

    public function test_super_admin_without_university_scope_is_refused(): void
    {
        DB::table('user_access_scopes')->where('user_id', self::SUPER)->delete();
        $this->actingAsUser(self::SUPER);
        $this->getJson(self::API.'/faculty')->assertForbidden();
        $this->getJson(self::API.'/dashboard')->assertForbidden();
    }

    public function test_generic_crud_routes_do_not_open_a_bypass_for_the_vp(): void
    {
        $this->actingAsUser(self::VP);
        $this->postJson('/api/v1/faculty-members', ['employee_id' => 20, 'is_active' => true])->assertForbidden();
        $this->postJson('/api/v1/employees', ['employee_number' => 'X-1', 'first_name' => 'a', 'last_name' => 'b', 'employee_type_id' => 1, 'employee_status_id' => 1])->assertForbidden();
        $this->postJson('/api/v1/employee-unit-assignments', ['employee_id' => 20, 'organizational_unit_id' => self::UNIT_A, 'start_date' => '2026-01-01'])->assertForbidden();
        $this->putJson('/api/v1/users/'.self::CANDIDATE.'/identity', ['employee_id' => 20])->assertForbidden();
        $this->postJson('/api/v1/user-roles', ['user_id' => self::CANDIDATE, 'role_id' => self::ROLE_DEAN])->assertStatus(405);
        $this->postJson('/api/v1/user-access-scopes', ['user_id' => self::CANDIDATE, 'scope_type' => 'college', 'scope_id' => 1])->assertNotFound();
        self::assertSame(0, DB::table('faculty_members')->where('employee_id', 20)->count());
        self::assertSame(0, DB::table('user_roles')->where('user_id', self::CANDIDATE)->where('role_id', self::ROLE_DEAN)->count());
    }

    // ── Teachers ─────────────────────────────────────────────────────────────

    public function test_created_teacher_affiliated_to_a_college_appears_for_that_dean_only_and_gets_no_assignment(): void
    {
        $this->actingAsUser(self::VP);
        $response = $this->postJson(self::API.'/faculty', $this->newTeacher(['college_id' => self::COLLEGE_A]))
            ->assertCreated()
            ->assertJsonPath('data.colleges.0.college_id', self::COLLEGE_A)
            ->assertJsonPath('data.effective_assignments_count', 0);
        $memberId = (int) $response->json('data.faculty_member_id');
        $employeeId = (int) $response->json('data.employee_id');

        self::assertSame(0, DB::table('course_offering_instructors')->where('faculty_member_id', $memberId)->count());
        self::assertSame(0, DB::table('teaching_assignment_requests')->where('faculty_member_id', $memberId)->count());
        self::assertSame(0, DB::table('users')->where('employee_id', $employeeId)->count(), 'no login account is created for a teacher');
        self::assertSame('academic', DB::table('employees as e')->join('employee_types as t', 't.employee_type_id', '=', 'e.employee_type_id')->where('e.employee_id', $employeeId)->value('t.type_code'));
        $this->assertAudit('faculty.profile_created');
        $this->assertAudit('faculty.affiliation_assign');

        $this->actingAsUser(self::DEAN_A);
        $ids = collect($this->getJson('/api/v1/teaching-staff?per_page=100')->assertOk()->json('data.data'))->pluck('faculty_member_id');
        self::assertContains($memberId, $ids->all());

        $picker = collect($this->getJson('/api/v1/teaching-staff/assignment-instructors?per_page=100&course_offering_id=1')->assertOk()->json('data.data'));
        self::assertTrue((bool) $picker->firstWhere('faculty_member_id', $memberId)['in_offering_college']);
        self::assertTrue($picker->first()['in_offering_college'], 'college members are listed first');
        $outsider = $picker->firstWhere('faculty_member_id', 2);
        self::assertNotNull($outsider, 'the picker stays university-wide');
        self::assertFalse((bool) $outsider['in_offering_college']);

        $this->actingAsUser($this->deanOfCollegeB());
        $ids = collect($this->getJson('/api/v1/teaching-staff?per_page=100')->assertOk()->json('data.data'))->pluck('faculty_member_id');
        self::assertNotContains($memberId, $ids->all());
    }

    public function test_transfer_moves_visibility_and_keeps_history(): void
    {
        $this->actingAsUser(self::VP);
        $memberId = (int) $this->postJson(self::API.'/faculty', $this->newTeacher(['college_id' => self::COLLEGE_A]))->assertCreated()->json('data.faculty_member_id');
        $assignmentId = (int) DB::table('employee_unit_assignments')->where('organizational_unit_id', self::UNIT_A)->where('employee_id', DB::table('faculty_members')->where('faculty_member_id', $memberId)->value('employee_id'))->value('assignment_id');

        // Stale expected id → 409, nothing changes.
        $this->postJson(self::API."/faculty/{$memberId}/affiliation", ['mode' => 'transfer', 'from_college_id' => self::COLLEGE_A, 'college_id' => self::COLLEGE_B, 'expected_assignment_id' => $assignmentId + 99])
            ->assertStatus(409)->assertJsonPath('error_code', 'affiliation_stale');

        $this->postJson(self::API."/faculty/{$memberId}/affiliation", ['mode' => 'transfer', 'from_college_id' => self::COLLEGE_A, 'college_id' => self::COLLEGE_B, 'expected_assignment_id' => $assignmentId, 'start_date' => now()->toDateString()])
            ->assertOk()
            ->assertJsonPath('data.colleges.0.college_id', self::COLLEGE_B)
            ->assertJsonCount(2, 'data.affiliation_history');

        $closed = DB::table('employee_unit_assignments')->where('assignment_id', $assignmentId)->first();
        self::assertSame(0, (int) $closed->is_active);
        self::assertNotNull($closed->end_date);

        $this->actingAsUser(self::DEAN_A);
        self::assertNotContains($memberId, collect($this->getJson('/api/v1/teaching-staff?per_page=100')->json('data.data'))->pluck('faculty_member_id')->all());
        $this->actingAsUser($this->deanOfCollegeB());
        self::assertContains($memberId, collect($this->getJson('/api/v1/teaching-staff?per_page=100')->json('data.data'))->pluck('faculty_member_id')->all());

        // Repeating the same transfer is refused as stale (idempotent guard).
        $this->actingAsUser(self::VP);
        $this->postJson(self::API."/faculty/{$memberId}/affiliation", ['mode' => 'transfer', 'from_college_id' => self::COLLEGE_A, 'college_id' => self::COLLEGE_B, 'expected_assignment_id' => $assignmentId])
            ->assertStatus(409);
        $this->postJson(self::API."/faculty/{$memberId}/affiliation", ['mode' => 'assign', 'college_id' => self::COLLEGE_B])
            ->assertStatus(409)->assertJsonPath('error_code', 'already_affiliated');
    }

    public function test_link_existing_employee_verifies_identity_and_refuses_duplicates(): void
    {
        $this->actingAsUser(self::VP);
        $this->postJson(self::API.'/faculty', ['mode' => 'link', 'employee_id' => 20, 'employee_number' => 'E-20', 'last_name' => 'Wrong'])
            ->assertStatus(422)->assertJsonPath('error_code', 'employee_identity_mismatch');
        $this->postJson(self::API.'/faculty', ['mode' => 'link', 'employee_id' => 21, 'employee_number' => 'E-21', 'last_name' => 'Retired'])
            ->assertStatus(422)->assertJsonPath('error_code', 'employee_inactive');
        $this->postJson(self::API.'/faculty', ['mode' => 'link', 'employee_id' => 20, 'employee_number' => 'E-20', 'last_name' => 'free'])
            ->assertCreated()->assertJsonPath('data.employee_id', 20);
        $this->postJson(self::API.'/faculty', ['mode' => 'link', 'employee_id' => 20, 'employee_number' => 'E-20', 'last_name' => 'Free'])
            ->assertStatus(409)->assertJsonPath('error_code', 'faculty_profile_exists');
        $this->postJson(self::API.'/faculty', $this->newTeacher(['employee_number' => 'E-20']))->assertStatus(422)->assertJsonValidationErrors('employee_number');
        $this->postJson(self::API.'/faculty', $this->newTeacher(['employee_status_id' => 2]))->assertStatus(422)->assertJsonValidationErrors('employee_status_id');
        self::assertSame(1, DB::table('faculty_members')->where('employee_id', 20)->count());
    }

    public function test_affiliation_validates_college_employee_and_profile_status(): void
    {
        $this->actingAsUser(self::VP);
        $this->postJson(self::API.'/faculty/1/affiliation', ['mode' => 'assign', 'college_id' => self::COLLEGE_NO_UNIT])
            ->assertStatus(422)->assertJsonPath('error_code', 'college_invalid');
        DB::table('faculty_members')->where('faculty_member_id', 2)->update(['is_active' => 0]);
        $this->postJson(self::API.'/faculty/2/affiliation', ['mode' => 'assign', 'college_id' => self::COLLEGE_A])
            ->assertStatus(422)->assertJsonPath('error_code', 'faculty_profile_inactive');
        DB::table('employees')->where('employee_id', 31)->update(['employee_status_id' => 2]);
        $this->postJson(self::API.'/faculty/1/affiliation', ['mode' => 'assign', 'college_id' => self::COLLEGE_B])
            ->assertStatus(422)->assertJsonPath('error_code', 'employee_inactive');
    }

    public function test_update_changes_only_allowed_fields_and_is_audited(): void
    {
        $this->actingAsUser(self::VP);
        $this->patchJson(self::API.'/faculty/1', ['specialization' => 'محاسبة', 'employee_status_id' => 2])->assertStatus(422);
        $this->patchJson(self::API.'/faculty/1', ['specialization' => 'محاسبة', 'phone_number' => '0999'])
            ->assertOk()->assertJsonPath('data.specialization', 'محاسبة')->assertJsonPath('data.phone_number', '0999');
        self::assertSame(1, (int) DB::table('employees')->where('employee_id', 31)->value('employee_status_id'));
        $log = $this->assertAudit('faculty.profile_updated');
        self::assertStringContainsString('محاسبة', $log->description);
    }

    public function test_faculty_list_filters_by_college_search_and_without_college(): void
    {
        $this->actingAsUser(self::VP);
        $this->getJson(self::API.'/faculty?college_id='.self::COLLEGE_A)->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.faculty_member_id', 1);
        $this->getJson(self::API.'/faculty?college_id=0')->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.faculty_member_id', 2);
        $this->getJson(self::API.'/faculty?search=E-31')->assertOk()->assertJsonCount(1, 'data.data');
        $this->getJson(self::API.'/faculty?search=%25')->assertOk()->assertJsonCount(0, 'data.data');
    }

    // ── Deans ────────────────────────────────────────────────────────────────

    public function test_new_dean_gets_dean_role_and_one_college_scope_and_can_use_the_dean_portal(): void
    {
        $this->actingAsUser(self::VP);
        $response = $this->postJson(self::API.'/deans', $this->appointNew())->assertOk()->assertJsonPath('data.changed', true);
        $deanId = (int) $response->json('data.user_id');

        $user = DB::table('users')->where('user_id', $deanId)->first();
        self::assertTrue(Hash::check(self::STRONG_PASSWORD, $user->password_hash));
        self::assertStringNotContainsString(self::STRONG_PASSWORD, $response->getContent());
        self::assertSame(['dean'], User::find($deanId)->effectiveRoles()->all());
        $this->assertDeanScopes($deanId, [self::COLLEGE_B]);
        self::assertSame(0, DB::table('user_access_scopes')->where('user_id', $deanId)->where('scope_type', 'university')->count());
        self::assertSame(1, DB::table('employee_positions')->where('employee_id', $user->employee_id)->where('organizational_unit_id', self::UNIT_B)->whereNull('end_date')->count());
        $log = $this->assertAudit('dean.appointed');
        self::assertStringNotContainsString(self::STRONG_PASSWORD, $log->description);
        self::assertStringNotContainsString('password', $log->description);

        $this->actingAsUser($deanId);
        $this->getJson('/api/user')->assertOk()
            ->assertJsonPath('data.college.college_id', self::COLLEGE_B)
            ->assertJsonPath('data.roles', ['dean']);
        $ids = collect($this->getJson('/api/v1/teaching-staff?per_page=100')->assertOk()->json('data.data'))->pluck('faculty_member_id');
        self::assertNotContains(1, $ids->all(), 'a college A teacher is not visible to the college B dean');
    }

    public function test_appoint_is_idempotent_and_refuses_stale_or_occupied_colleges(): void
    {
        $this->actingAsUser(self::VP);
        $deanId = (int) $this->postJson(self::API.'/deans', $this->appointNew())->assertOk()->json('data.user_id');

        // Same request again: the new-account path now conflicts (employee already exists / has account).
        $this->postJson(self::API.'/deans', $this->appointNew())->assertStatus(422);
        // Linking the same existing account again is a no-op.
        $employeeId = (int) DB::table('users')->where('user_id', $deanId)->value('employee_id');
        $this->postJson(self::API.'/deans', $this->appointExisting($deanId, $employeeId, 'E-900', 'Dean', self::COLLEGE_B, $deanId))
            ->assertOk()->assertJsonPath('data.changed', false);

        // College A already has a dean: stale expectation → 409; no replace flag → 409.
        $this->postJson(self::API.'/deans', $this->appointExisting(self::CANDIDATE, 8, 'E-8', 'Candidate', self::COLLEGE_A, null))
            ->assertStatus(409)->assertJsonPath('error_code', 'dean_state_stale');
        $this->postJson(self::API.'/deans', $this->appointExisting(self::CANDIDATE, 8, 'E-8', 'Candidate', self::COLLEGE_A, self::DEAN_A))
            ->assertStatus(409)->assertJsonPath('error_code', 'college_has_dean');
        $this->assertDeanScopes(self::DEAN_A, [self::COLLEGE_A]);

        // Explicit replacement ends the previous dean without deleting the account.
        $this->postJson(self::API.'/deans', $this->appointExisting(self::CANDIDATE, 8, 'E-8', 'Candidate', self::COLLEGE_A, self::DEAN_A) + ['replace_current' => true])
            ->assertOk();
        $this->assertDeanScopes(self::CANDIDATE, [self::COLLEGE_A]);
        $this->assertDeanScopes(self::DEAN_A, []);
        self::assertNotNull(DB::table('users')->where('user_id', self::DEAN_A)->first());
        self::assertSame(0, (int) DB::table('user_roles')->where('user_id', self::DEAN_A)->where('role_id', self::ROLE_DEAN)->value('is_active'));
        self::assertSame(1, (int) DB::table('user_roles')->where('user_id', self::CANDIDATE)->where('role_id', self::ROLE_INSTRUCTOR)->value('is_active'), 'other compatible roles stay');

        $this->actingAsUser(self::DEAN_A);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('data.college', null);
        $this->getJson('/api/v1/teaching-staff')->assertForbidden();
    }

    public function test_escalation_attempts_are_refused(): void
    {
        $this->actingAsUser(self::VP);
        // super_admin, foreign-role and university-scope accounts, and the actor's own account.
        $this->postJson(self::API.'/deans', $this->appointExisting(self::SUPER, 1, 'E-1', 'Admin', self::COLLEGE_B, null))
            ->assertForbidden()->assertJsonPath('error_code', 'protected_account');
        $this->postJson(self::API.'/deans', $this->appointExisting(self::FOREIGN, 9, 'E-9', 'Exam', self::COLLEGE_B, null))
            ->assertForbidden()->assertJsonPath('error_code', 'protected_account');
        $this->postJson(self::API.'/deans', $this->appointExisting(self::DEAN_OTHER_UNIVERSITY, 12, 'E-12', 'Wide', self::COLLEGE_B, null))
            ->assertForbidden()->assertJsonPath('error_code', 'protected_account');
        $this->postJson(self::API.'/deans', $this->appointExisting(self::VP, 2, 'E-2', 'Vp', self::COLLEGE_B, null))
            ->assertForbidden()->assertJsonPath('error_code', 'self_change_forbidden');
        // Client-supplied roles/scopes/hashes are rejected outright.
        $this->postJson(self::API.'/deans', $this->appointNew() + ['scope_type' => 'university'])->assertStatus(422)->assertJsonValidationErrors('scope_type');
        $payload = $this->appointNew();
        $payload['account']['role_ids'] = [self::ROLE_SUPER];
        $this->postJson(self::API.'/deans', $payload)->assertStatus(422)->assertJsonValidationErrors('account.role_ids');
        // A dean role carrying restricted powers is refused (fail closed).
        DB::table('role_permissions')->insert(['role_id' => self::ROLE_DEAN, 'permission_id' => 4, 'granted_at' => now()]);
        $this->postJson(self::API.'/deans', $this->appointNew())->assertForbidden()->assertJsonPath('error_code', 'dean_role_restricted');
        DB::table('role_permissions')->where('role_id', self::ROLE_DEAN)->where('permission_id', 4)->delete();

        self::assertSame(1, DB::table('user_roles')->where('user_id', self::SUPER)->count());
        self::assertSame(0, DB::table('user_roles')->where('role_id', self::ROLE_DEAN)->whereIn('user_id', [self::SUPER, self::FOREIGN, self::VP])->count());
        self::assertSame(0, DB::table('user_access_scopes')->where('scope_type', 'college')->whereIn('user_id', [self::SUPER, self::FOREIGN, self::VP])->count());
        self::assertSame(0, DB::table('users')->where('username', 'new.dean')->count());
        $this->assertDeanScopes(self::DEAN_OTHER_UNIVERSITY, []);
    }

    public function test_weak_password_and_duplicate_username_are_rejected(): void
    {
        $this->actingAsUser(self::VP);
        $payload = $this->appointNew();
        $payload['account']['password'] = $payload['account']['password_confirmation'] = 'short';
        $this->postJson(self::API.'/deans', $payload)->assertStatus(422)->assertJsonValidationErrors('account.password');
        $payload = $this->appointNew();
        $payload['account']['username'] = 'CANDIDATE';
        $this->postJson(self::API.'/deans', $payload)->assertStatus(422)->assertJsonValidationErrors('account.username');
        self::assertSame(0, DB::table('employees')->where('employee_number', 'E-900')->count(), 'the transaction rolled back the employee');
    }

    public function test_transfer_moves_the_scope_and_end_keeps_the_account(): void
    {
        $this->actingAsUser(self::VP);
        $this->postJson(self::API.'/deans/'.self::COLLEGE_A.'/transfer', ['dean_user_id' => self::DEAN_A, 'to_college_id' => self::COLLEGE_B, 'expected_target_dean_user_id' => null])
            ->assertOk();
        $this->assertDeanScopes(self::DEAN_A, [self::COLLEGE_B]);
        self::assertSame(0, (int) DB::table('user_access_scopes')->where('user_id', self::DEAN_A)->where('scope_id', self::COLLEGE_A)->value('is_active'));
        self::assertSame(1, DB::table('employee_positions')->where('employee_id', 6)->where('organizational_unit_id', self::UNIT_A)->whereNotNull('end_date')->count());
        self::assertSame(1, DB::table('employee_positions')->where('employee_id', 6)->where('organizational_unit_id', self::UNIT_B)->whereNull('end_date')->count());
        $this->assertAudit('dean.transferred');

        // A repeated (stale) transfer is refused.
        $this->postJson(self::API.'/deans/'.self::COLLEGE_A.'/transfer', ['dean_user_id' => self::DEAN_A, 'to_college_id' => self::COLLEGE_B, 'expected_target_dean_user_id' => null])
            ->assertStatus(409)->assertJsonPath('error_code', 'dean_state_stale');

        $this->actingAsUser(self::DEAN_A);
        $this->getJson('/api/user')->assertJsonPath('data.college.college_id', self::COLLEGE_B);

        $this->actingAsUser(self::VP);
        $this->postJson(self::API.'/deans/'.self::COLLEGE_B.'/end', ['dean_user_id' => self::DEAN_A])->assertOk();
        $this->assertDeanScopes(self::DEAN_A, []);
        self::assertSame(1, DB::table('users')->where('user_id', self::DEAN_A)->count());
        self::assertSame(0, (int) DB::table('user_roles')->where('user_id', self::DEAN_A)->where('role_id', self::ROLE_DEAN)->value('is_active'));
        self::assertSame(0, DB::table('employee_positions')->where('employee_id', 6)->whereNull('end_date')->count());
        $this->assertAudit('dean.ended');
        $this->postJson(self::API.'/deans/'.self::COLLEGE_B.'/end', ['dean_user_id' => self::DEAN_A])->assertStatus(409);

        $list = collect($this->getJson(self::API.'/deans')->assertOk()->json('data.colleges'));
        self::assertSame('no_dean', $list->firstWhere('college_id', self::COLLEGE_B)['warning']);
    }

    public function test_account_lookup_reports_eligibility_without_secrets(): void
    {
        $this->actingAsUser(self::VP);
        $this->getJson(self::API.'/deans/account-lookup?username=candidate')->assertOk()
            ->assertJsonPath('data.eligible', true)->assertJsonMissingPath('data.password_hash');
        $this->getJson(self::API.'/deans/account-lookup?username=exam.user')->assertOk()->assertJsonPath('data.eligible', false);
        $this->getJson(self::API.'/deans/account-lookup?username=admin')->assertOk()->assertJsonPath('data.eligible', false);
    }

    // ── Dashboard ────────────────────────────────────────────────────────────

    public function test_dashboard_counts_match_precomputed_values_and_filters(): void
    {
        $this->actingAsUser(self::VP);
        $data = $this->getJson(self::API.'/dashboard')->assertOk()->json('data');
        // Students: 3 active in A, 1 active in B, 1 suspended + 1 soft-deleted are excluded.
        self::assertSame(4, $data['students']['university_total']);
        self::assertEqualsCanonicalizing([['college_id' => 1, 'total' => 3], ['college_id' => 2, 'total' => 1]], $data['students']['by_college']);
        // Faculty: member 1 in A (home unit), member 2 without college.
        self::assertSame(2, $data['faculty']['university_total']);
        self::assertSame(1, $data['faculty']['without_college']);
        self::assertSame([['college_id' => 1, 'total' => 1]], $data['faculty']['by_college']);
        // Workflow: see seedFixture — A: 1 pending + 1 returned, B: 1 approved.
        self::assertSame(['pending' => 1, 'returned' => 1, 'approved' => 1], $data['teaching_assignments']['totals']);

        $filtered = $this->getJson(self::API.'/dashboard?college_id='.self::COLLEGE_B)->assertOk()->json('data');
        self::assertNull($filtered['students']['university_total']);
        self::assertSame([['college_id' => 2, 'total' => 1]], $filtered['students']['by_college']);
        self::assertSame(['pending' => 0, 'returned' => 0, 'approved' => 1], $filtered['teaching_assignments']['totals']);

        $this->getJson(self::API.'/dashboard?semester_id=1')->assertStatus(422)->assertJsonPath('error_code', 'dashboard_semester_requires_year');
        $this->getJson(self::API.'/dashboard?college_id=999')->assertStatus(422)->assertJsonPath('error_code', 'dashboard_college_unknown');
        $empty = $this->getJson(self::API.'/dashboard?academic_year_id=2')->assertOk()->json('data');
        self::assertSame(['pending' => 0, 'returned' => 0, 'approved' => 0], $empty['teaching_assignments']['totals']);
    }

    public function test_dashboard_marks_workflow_unavailable_instead_of_zero(): void
    {
        $this->actingAsUser(self::SUPER);
        $data = $this->getJson(self::API.'/dashboard')->assertOk()->json('data');
        self::assertTrue($data['teaching_assignments']['available']);

        DB::table('role_permissions')->where('role_id', self::ROLE_VP)->where('permission_id', 6)->delete();
        $this->actingAsUser(self::VP);
        $data = $this->getJson(self::API.'/dashboard')->assertOk()->json('data');
        self::assertFalse($data['teaching_assignments']['available']);
        self::assertSame('review_permission_missing', $data['teaching_assignments']['reason']);
        self::assertArrayNotHasKey('totals', $data['teaching_assignments']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function deanOfCollegeB(): int
    {
        $userId = 40;
        if (! DB::table('users')->where('user_id', $userId)->exists()) {
            $this->user($userId, 'dean.b', employeeId: null);
            DB::table('user_roles')->insert(['user_id' => $userId, 'role_id' => self::ROLE_DEAN, 'is_active' => 1]);
            DB::table('user_access_scopes')->insert(['user_id' => $userId, 'scope_type' => 'college', 'scope_id' => self::COLLEGE_B, 'is_active' => 1]);
        }

        return $userId;
    }

    private function actingAsUser(int $userId): void
    {
        Sanctum::actingAs(User::query()->findOrFail($userId));
    }

    /** @param list<int> $collegeIds */
    private function assertDeanScopes(int $userId, array $collegeIds): void
    {
        $active = DB::table('user_access_scopes')->where('user_id', $userId)->where('is_active', 1)
            ->where('scope_type', 'college')->pluck('scope_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        self::assertSame($collegeIds, $active);
    }

    private function assertAudit(string $action): object
    {
        $log = DB::table('user_activity_logs')->where('module_code', 'vice_presidency')->where('action_code', $action)->orderByDesc('activity_log_id')->first();
        self::assertNotNull($log, "audit row {$action} missing");

        return $log;
    }

    private function newTeacher(array $overrides = []): array
    {
        return array_merge([
            'mode' => 'new',
            'employee_number' => 'E-500',
            'first_name' => 'سامر',
            'last_name' => 'حداد',
            'academic_rank' => 'مدرس',
            'specialization' => 'إدارة',
        ], $overrides);
    }

    private function appointNew(): array
    {
        return [
            'college_id' => self::COLLEGE_B,
            'expected_current_dean_user_id' => null,
            'employee' => ['mode' => 'new', 'employee_number' => 'E-900', 'first_name' => 'New', 'last_name' => 'Dean'],
            'account' => ['mode' => 'new', 'username' => 'new.dean', 'email' => 'New.Dean@alrowad.test', 'password' => self::STRONG_PASSWORD, 'password_confirmation' => self::STRONG_PASSWORD],
        ];
    }

    private function appointExisting(int $userId, int $employeeId, string $number, string $lastName, int $collegeId, ?int $expected): array
    {
        return [
            'college_id' => $collegeId,
            'expected_current_dean_user_id' => $expected,
            'employee' => ['mode' => 'existing', 'employee_id' => $employeeId, 'employee_number' => $number, 'last_name' => $lastName],
            'account' => ['mode' => 'existing', 'user_id' => $userId],
        ];
    }
}
