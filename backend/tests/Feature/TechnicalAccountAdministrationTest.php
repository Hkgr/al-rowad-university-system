<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Real HTTP + SQLite persistence for the Technical Office account-administration API. */
final class TechnicalAccountAdministrationTest extends TestCase
{
    private const URL = '/api/v1/technical/accounts';

    private const ADMIN = 1;
    private const TECH = 2;
    private const STAFF = 3;
    private const DEAN = 4;
    private const STUDENT = 5;
    private const SECOND_TECH = 6;

    private const ROLE_SUPER_ADMIN = 1;
    private const ROLE_TECHNICAL = 2;
    private const ROLE_INSTRUCTOR = 3;
    private const ROLE_EXAM = 4;
    private const ROLE_DEAN = 5;
    private const ROLE_VP_SCI = 6;
    private const ROLE_STUDENT = 7;
    private const ROLE_CUSTOM = 8;
    private const ROLE_HR = 9;
    private const ROLE_INACTIVE_LIBRARIAN = 10;

    private const STRONG_PASSWORD = 'Strong-Pass-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
    }

    // ── Authentication / permission boundary ──────────────────────────────

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->postJson(self::URL, $this->newAccount())->assertUnauthorized();
    }

    public function test_account_without_permission_is_forbidden_on_every_route(): void
    {
        $this->actingAsUser(self::STAFF);
        $this->getJson(self::URL)->assertForbidden();
        $this->getJson(self::URL.'/options')->assertForbidden();
        $this->getJson(self::URL.'/'.self::STAFF)->assertForbidden();
        $this->postJson(self::URL, $this->newAccount())->assertForbidden();
        $this->postJson(self::URL.'/'.self::STUDENT.'/roles', ['role_id' => self::ROLE_EXAM])->assertForbidden();
        $this->deleteJson(self::URL.'/'.self::STAFF.'/roles/'.self::ROLE_INSTRUCTOR)->assertForbidden();
        $this->putJson(self::URL.'/'.self::STAFF.'/status', ['account_status' => 'disabled'])->assertForbidden();
        self::assertSame(6, DB::table('users')->count());
    }

    public function test_view_only_permission_cannot_mutate(): void
    {
        DB::table('role_permissions')->where('role_id', self::ROLE_TECHNICAL)->where('permission_id', 3)->delete();
        $this->actingAsUser(self::TECH);
        $this->getJson(self::URL)->assertOk();
        $this->postJson(self::URL, $this->newAccount())->assertForbidden();
        $this->postJson(self::URL.'/'.self::STAFF.'/roles', ['role_id' => self::ROLE_EXAM])->assertForbidden();
        $this->putJson(self::URL.'/'.self::STAFF.'/status', ['account_status' => 'disabled'])->assertForbidden();
    }

    public function test_organizational_placement_alone_grants_nothing(): void
    {
        // A user with no roles, even if placed in the Technical Office unit, has no access.
        DB::table('user_roles')->where('user_id', self::TECH)->delete();
        $this->actingAsUser(self::TECH);
        $this->getJson(self::URL)->assertForbidden();
    }

    // ── Listing / detail ──────────────────────────────────────────────────

    public function test_list_is_searchable_paginated_and_never_exposes_password_hash(): void
    {
        $this->actingAsUser(self::TECH);
        $response = $this->getJson(self::URL.'?per_page=2')->assertOk()
            ->assertJsonPath('data.meta.total', 6)
            ->assertJsonPath('data.meta.last_page', 3)
            ->assertJsonCount(2, 'data.data');
        self::assertStringNotContainsString('password', $response->getContent());

        $this->getJson(self::URL.'?search=instructor')->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.username', 'instructor')
            ->assertJsonPath('data.data.0.can_manage', true);
        $this->getJson(self::URL.'?search=admin')->assertOk()->assertJsonPath('data.data.0.can_manage', false);
        $this->getJson(self::URL.'?role_id='.self::ROLE_STUDENT)->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->getJson(self::URL.'?status=disabled')->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->getJson(self::URL.'?search=%25')->assertOk()->assertJsonPath('data.meta.total', 0);
    }

    public function test_detail_shows_role_derived_permissions_and_capabilities(): void
    {
        $this->actingAsUser(self::TECH);
        $response = $this->getJson(self::URL.'/'.self::STAFF)->assertOk()
            ->assertJsonPath('data.effective_permissions.0.permission_code', 'grades.manage')
            ->assertJsonPath('data.effective_permissions.0.granted_by_roles.0.role_code', 'doctor_instructor')
            ->assertJsonPath('data.capabilities.can_manage', true)
            ->assertJsonPath('data.employee_id', 50);
        self::assertStringNotContainsString('password', $response->getContent());

        $this->getJson(self::URL.'/'.self::ADMIN)->assertOk()
            ->assertJsonPath('data.capabilities.can_manage', false)
            ->assertJsonPath('data.capabilities.restriction', 'protected_account')
            ->assertJsonPath('data.super_admin_bypass', true);
        $this->getJson(self::URL.'/'.self::TECH)->assertOk()
            ->assertJsonPath('data.capabilities.restriction', 'self_change_forbidden')
            ->assertJsonPath('data.is_current_user', true);
    }

    public function test_options_mark_only_allowlisted_roles_assignable_for_technical_team(): void
    {
        $this->actingAsUser(self::TECH);
        $roles = collect($this->getJson(self::URL.'/options')->assertOk()->json('data.roles'))->keyBy('role_code');
        self::assertTrue($roles['doctor_instructor']['assignable']);
        self::assertTrue($roles['exam_officer']['assignable']);
        foreach (['super_admin', 'technical_team', 'dean', 'vice_president_scientific', 'student', 'custom_future_role'] as $code) {
            self::assertFalse($roles[$code]['assignable'], $code);
            self::assertSame('role_not_assignable', $roles[$code]['restriction'], $code);
        }
        self::assertSame('role_inactive', $roles['librarian']['restriction']);
        self::assertSame(['exams.view'], array_column($roles['exam_officer']['permissions'], 'permission_code'));

        $this->actingAsUser(self::ADMIN);
        $roles = collect($this->getJson(self::URL.'/options')->assertOk()->json('data.roles'))->keyBy('role_code');
        self::assertTrue($roles['super_admin']['assignable']);
        self::assertTrue($roles['custom_future_role']['assignable']);
    }

    // ── Account creation ──────────────────────────────────────────────────

    public function test_create_account_hashes_password_on_server_and_audits(): void
    {
        $this->actingAsUser(self::TECH);
        $response = $this->postJson(self::URL, $this->newAccount(['role_ids' => [self::ROLE_EXAM]]))
            ->assertCreated()
            ->assertJsonPath('data.username', 'new.officer')
            ->assertJsonPath('data.roles.0.role_code', 'exam_officer')
            ->assertJsonPath('data.effective_permissions.0.permission_code', 'exams.view');
        $content = $response->getContent();
        self::assertStringNotContainsString(self::STRONG_PASSWORD, $content);
        self::assertStringNotContainsString('password', $content);

        $row = DB::table('users')->where('username', 'new.officer')->first();
        self::assertNotSame(self::STRONG_PASSWORD, $row->password_hash);
        self::assertTrue(Hash::check(self::STRONG_PASSWORD, $row->password_hash));
        self::assertSame(self::TECH, (int) $row->created_by_user_id);
        self::assertSame('new.officer@alrowad.test', $row->email);
        self::assertSame(1, (int) $row->account_status_id);
        self::assertNull($row->student_id);

        $assignment = DB::table('user_roles')->where('user_id', $row->user_id)->first();
        self::assertSame(self::TECH, (int) $assignment->assigned_by_user_id);
        self::assertNotNull($assignment->assigned_at);

        $log = DB::table('user_activity_logs')->where('action_code', 'account.created')->first();
        self::assertSame(self::TECH, (int) $log->user_id);
        self::assertStringNotContainsString(self::STRONG_PASSWORD, (string) $log->description);
    }

    public function test_create_account_validation_returns_readable_422(): void
    {
        $this->actingAsUser(self::TECH);
        $this->postJson(self::URL, $this->newAccount(['username' => 'INSTRUCTOR']))->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->postJson(self::URL, $this->newAccount(['email' => 'Instructor@Alrowad.test']))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson(self::URL, $this->newAccount(['password' => 'short', 'password_confirmation' => 'short']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson(self::URL, $this->newAccount(['password' => 'alllowercase123', 'password_confirmation' => 'alllowercase123']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson(self::URL, $this->newAccount(['password_confirmation' => 'Different-2026x']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson(self::URL, $this->newAccount(['account_status' => 'locked']))->assertUnprocessable()->assertJsonValidationErrors('account_status');
        $this->postJson(self::URL, $this->newAccount(['password_hash' => Hash::make('x')]))->assertUnprocessable()->assertJsonValidationErrors('password_hash');
        $this->postJson(self::URL, $this->newAccount(['created_by_user_id' => self::ADMIN]))->assertUnprocessable()->assertJsonValidationErrors('created_by_user_id');
        $this->postJson(self::URL, $this->newAccount(['student_id' => 77]))->assertUnprocessable()->assertJsonValidationErrors('student_id');
        $this->postJson(self::URL, $this->newAccount(['username' => 'bad name!']))->assertUnprocessable()->assertJsonValidationErrors('username');
        $message = $this->postJson(self::URL, $this->newAccount(['username' => 'instructor']))->json('errors.username.0');
        self::assertSame('اسم المستخدم مستخدم مسبقًا.', $message);
        self::assertSame(6, DB::table('users')->count());
    }

    public function test_create_with_non_allowlisted_role_is_atomic_and_forbidden(): void
    {
        $this->actingAsUser(self::TECH);
        $this->postJson(self::URL, $this->newAccount(['role_ids' => [self::ROLE_EXAM, self::ROLE_SUPER_ADMIN]]))
            ->assertForbidden()->assertJsonPath('error_code', 'role_not_assignable');
        self::assertSame(6, DB::table('users')->count());
        self::assertSame(0, DB::table('user_activity_logs')->count());
    }

    // ── Role assignment / revocation ──────────────────────────────────────

    public function test_assign_and_revoke_are_audited_idempotent_and_reuse_the_row(): void
    {
        $this->actingAsUser(self::TECH);
        $this->postJson(self::URL.'/'.self::STAFF.'/roles', ['role_id' => self::ROLE_EXAM])->assertOk()
            ->assertJsonPath('data.roles.1.role_code', 'exam_officer');
        $row = DB::table('user_roles')->where('user_id', self::STAFF)->where('role_id', self::ROLE_EXAM)->first();
        self::assertSame(self::TECH, (int) $row->assigned_by_user_id);
        self::assertNotNull($row->assigned_at);

        $this->postJson(self::URL.'/'.self::STAFF.'/roles', ['role_id' => self::ROLE_EXAM])
            ->assertStatus(409)->assertJsonPath('error_code', 'role_already_assigned');

        $this->deleteJson(self::URL.'/'.self::STAFF.'/roles/'.self::ROLE_EXAM)->assertOk();
        self::assertSame(0, (int) DB::table('user_roles')->where('user_role_id', $row->user_role_id)->value('is_active'));
        $this->deleteJson(self::URL.'/'.self::STAFF.'/roles/'.self::ROLE_EXAM)
            ->assertStatus(409)->assertJsonPath('error_code', 'role_not_assigned');

        $this->postJson(self::URL.'/'.self::STAFF.'/roles', ['role_id' => self::ROLE_EXAM])->assertOk();
        self::assertSame(1, DB::table('user_roles')->where('user_id', self::STAFF)->where('role_id', self::ROLE_EXAM)->count());
        self::assertSame($row->user_role_id, DB::table('user_roles')->where('user_id', self::STAFF)->where('role_id', self::ROLE_EXAM)->value('user_role_id'));

        self::assertSame(
            ['account.role_assigned', 'account.role_revoked', 'account.role_assigned'],
            DB::table('user_activity_logs')->orderBy('activity_log_id')->pluck('action_code')->all()
        );
        self::assertSame(50, (int) DB::table('users')->where('user_id', self::STAFF)->value('employee_id'));
    }

    public function test_client_supplied_audit_fields_are_rejected(): void
    {
        $this->actingAsUser(self::TECH);
        $this->postJson(self::URL.'/'.self::STAFF.'/roles', ['role_id' => self::ROLE_EXAM, 'assigned_by_user_id' => self::ADMIN])
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_by_user_id');
        $this->postJson(self::URL.'/'.self::STAFF.'/roles', ['role_id' => self::ROLE_EXAM, 'assigned_at' => '2020-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_at');
    }

    public static function nonAllowlistedRoles(): array
    {
        return [
            'super_admin' => [self::ROLE_SUPER_ADMIN],
            'technical_team' => [self::ROLE_TECHNICAL],
            'dean' => [self::ROLE_DEAN],
            'vice_president_scientific' => [self::ROLE_VP_SCI],
            'student' => [self::ROLE_STUDENT],
            'custom_future_role (no permissions, not allowlisted)' => [self::ROLE_CUSTOM],
        ];
    }

    #[DataProvider('nonAllowlistedRoles')]
    public function test_technical_team_cannot_assign_or_revoke_roles_outside_the_allowlist(int $roleId): void
    {
        $this->actingAsUser(self::TECH);
        $this->postJson(self::URL.'/'.self::STAFF.'/roles', ['role_id' => $roleId])
            ->assertForbidden()->assertJsonPath('error_code', 'role_not_assignable');

        // Seed an (inactive, so the account stays manageable) row: revocation is refused by the allowlist itself.
        DB::table('user_roles')->insert(['user_id' => self::STAFF, 'role_id' => $roleId, 'is_active' => 0]);
        $this->deleteJson(self::URL.'/'.self::STAFF.'/roles/'.$roleId)->assertForbidden();
        self::assertSame(0, DB::table('user_activity_logs')->count());
    }

    public function test_allowlisted_role_that_gains_a_restricted_permission_fails_closed(): void
    {
        DB::table('role_permissions')->insert(['role_id' => self::ROLE_HR, 'permission_id' => 5]); // users_permissions.manage
        $this->actingAsUser(self::TECH);
        $this->postJson(self::URL.'/'.self::STAFF.'/roles', ['role_id' => self::ROLE_HR])
            ->assertForbidden()->assertJsonPath('error_code', 'role_carries_restricted_permission');
        self::assertFalse(DB::table('user_roles')->where('user_id', self::STAFF)->where('role_id', self::ROLE_HR)->exists());
    }

    public function test_inactive_role_cannot_be_assigned(): void
    {
        $this->actingAsUser(self::ADMIN);
        $this->postJson(self::URL.'/'.self::STAFF.'/roles', ['role_id' => self::ROLE_INACTIVE_LIBRARIAN])
            ->assertStatus(409)->assertJsonPath('error_code', 'role_inactive');
    }

    public function test_technical_team_cannot_change_itself_or_protected_accounts(): void
    {
        $this->actingAsUser(self::TECH);
        $this->postJson(self::URL.'/'.self::TECH.'/roles', ['role_id' => self::ROLE_EXAM])
            ->assertForbidden()->assertJsonPath('error_code', 'self_change_forbidden');
        $this->putJson(self::URL.'/'.self::TECH.'/status', ['account_status' => 'disabled'])
            ->assertForbidden()->assertJsonPath('error_code', 'self_change_forbidden');
        $this->putJson(self::URL.'/'.self::ADMIN.'/status', ['account_status' => 'disabled'])
            ->assertForbidden()->assertJsonPath('error_code', 'protected_account');
        $this->postJson(self::URL.'/'.self::DEAN.'/roles', ['role_id' => self::ROLE_EXAM])
            ->assertForbidden()->assertJsonPath('error_code', 'protected_account');
        $this->deleteJson(self::URL.'/'.self::SECOND_TECH.'/roles/'.self::ROLE_TECHNICAL)
            ->assertForbidden()->assertJsonPath('error_code', 'protected_account');
        $this->postJson(self::URL.'/'.self::STUDENT.'/roles', ['role_id' => self::ROLE_EXAM])
            ->assertForbidden()->assertJsonPath('error_code', 'protected_account');
        self::assertSame(1, (int) DB::table('users')->where('user_id', self::ADMIN)->value('account_status_id'));
    }

    // ── Last super_admin ──────────────────────────────────────────────────

    public function test_last_active_super_admin_cannot_be_revoked_or_disabled(): void
    {
        $this->actingAsUser(self::ADMIN);
        $this->deleteJson(self::URL.'/'.self::ADMIN.'/roles/'.self::ROLE_SUPER_ADMIN)
            ->assertForbidden()->assertJsonPath('error_code', 'last_super_admin');
        $this->putJson(self::URL.'/'.self::ADMIN.'/status', ['account_status' => 'disabled'])
            ->assertForbidden()->assertJsonPath('error_code', 'self_change_forbidden');

        // Promote a second admin; a disabled super_admin does not count as active.
        $this->postJson(self::URL.'/'.self::STAFF.'/roles', ['role_id' => self::ROLE_SUPER_ADMIN])->assertOk();
        DB::table('users')->where('user_id', self::STAFF)->update(['account_status_id' => 2]);
        $this->deleteJson(self::URL.'/'.self::ADMIN.'/roles/'.self::ROLE_SUPER_ADMIN)
            ->assertForbidden()->assertJsonPath('error_code', 'last_super_admin');

        DB::table('users')->where('user_id', self::STAFF)->update(['account_status_id' => 1]);
        $this->deleteJson(self::URL.'/'.self::ADMIN.'/roles/'.self::ROLE_SUPER_ADMIN)->assertOk();

        // Role change by the remaining admin works; the next revoke would leave none.
        Sanctum::actingAs(User::findOrFail(self::STAFF));
        $this->putJson(self::URL.'/'.self::ADMIN.'/status', ['account_status' => 'disabled'])->assertOk();
        DB::table('user_roles')->where('user_id', self::ADMIN)->update(['is_active' => 1]);
        DB::table('users')->where('user_id', self::ADMIN)->update(['account_status_id' => 1]);
        Sanctum::actingAs(User::findOrFail(self::ADMIN));
        $this->deleteJson(self::URL.'/'.self::STAFF.'/roles/'.self::ROLE_SUPER_ADMIN)->assertOk();
        $this->deleteJson(self::URL.'/'.self::ADMIN.'/roles/'.self::ROLE_SUPER_ADMIN)
            ->assertForbidden()->assertJsonPath('error_code', 'last_super_admin');
    }

    // ── Status ────────────────────────────────────────────────────────────

    public function test_disable_revokes_tokens_and_enable_resets_failed_attempts(): void
    {
        $user = User::findOrFail(self::STAFF);
        $user->createToken('api-token');
        DB::table('users')->where('user_id', self::STAFF)->update(['failed_login_attempts' => 4]);
        self::assertSame(1, DB::table('personal_access_tokens')->count());

        $this->actingAsUser(self::TECH);
        $this->putJson(self::URL.'/'.self::STAFF.'/status', ['account_status' => 'disabled'])->assertOk()
            ->assertJsonPath('data.status.code', 'disabled');
        self::assertSame(0, DB::table('personal_access_tokens')->count());
        $this->putJson(self::URL.'/'.self::STAFF.'/status', ['account_status' => 'disabled'])
            ->assertStatus(409)->assertJsonPath('error_code', 'status_unchanged');
        $this->putJson(self::URL.'/'.self::STAFF.'/status', ['account_status' => 'active'])->assertOk();
        self::assertSame(0, (int) DB::table('users')->where('user_id', self::STAFF)->value('failed_login_attempts'));
        self::assertSame(50, (int) DB::table('users')->where('user_id', self::STAFF)->value('employee_id'));
        $this->putJson(self::URL.'/'.self::STAFF.'/status', ['account_status' => 'locked'])->assertUnprocessable();
    }

    public function test_student_link_is_preserved_when_super_admin_changes_roles(): void
    {
        $this->actingAsUser(self::ADMIN);
        $this->postJson(self::URL.'/'.self::STUDENT.'/roles', ['role_id' => self::ROLE_EXAM])->assertOk();
        $this->deleteJson(self::URL.'/'.self::STUDENT.'/roles/'.self::ROLE_EXAM)->assertOk();
        self::assertSame(77, (int) DB::table('users')->where('user_id', self::STUDENT)->value('student_id'));
        self::assertSame(1, (int) DB::table('user_roles')->where('user_id', self::STUDENT)->where('role_id', self::ROLE_STUDENT)->value('is_active'));
    }

    // ── Legacy generic routes cannot bypass the service ───────────────────

    public static function bypassActors(): array
    {
        return ['super_admin' => [self::ADMIN], 'technical_team' => [self::TECH]];
    }

    #[DataProvider('bypassActors')]
    public function test_generic_user_and_user_role_writes_are_not_routed_for_anyone(int $actor): void
    {
        $this->actingAsUser($actor);
        $before = $this->snapshot();
        $adminSuperRow = DB::table('user_roles')->where('user_id', self::ADMIN)->where('role_id', self::ROLE_SUPER_ADMIN)->value('user_role_id');

        $this->postJson('/api/v1/users', ['username' => 'x', 'email' => 'x@x.test', 'password_hash' => 'plain', 'account_status_id' => 1, 'failed_login_attempts' => 0])->assertStatus(405);
        $this->putJson('/api/v1/users/'.self::ADMIN, ['account_status_id' => 2])->assertStatus(405);
        $this->patchJson('/api/v1/users/'.self::ADMIN, ['account_status_id' => 2])->assertStatus(405);
        $this->deleteJson('/api/v1/users/'.self::ADMIN)->assertStatus(405);
        $this->postJson('/api/v1/user-roles', ['user_id' => self::TECH, 'role_id' => self::ROLE_SUPER_ADMIN, 'is_active' => 1])->assertStatus(405);
        $this->putJson('/api/v1/user-roles/'.$adminSuperRow, ['is_active' => 0])->assertStatus(405);
        $this->patchJson('/api/v1/user-roles/'.$adminSuperRow, ['is_active' => 0])->assertStatus(405);
        $this->deleteJson('/api/v1/user-roles/'.$adminSuperRow)->assertStatus(405);
        $this->postJson('/api/v1/user-activity-logs', ['user_id' => 1, 'action_code' => 'forged'])->assertStatus(405);
        $this->putJson('/api/v1/user-activity-logs/1', ['action_code' => 'forged'])->assertStatus(405);
        $this->deleteJson('/api/v1/user-activity-logs/1')->assertStatus(405);

        self::assertSame($before, $this->snapshot());
    }

    public function test_generic_role_definition_writes_are_super_admin_only_and_protect_super_admin_role(): void
    {
        $this->actingAsUser(self::TECH);
        $this->postJson('/api/v1/role-permissions', ['role_id' => self::ROLE_TECHNICAL, 'permission_id' => 5])->assertForbidden();
        $this->putJson('/api/v1/roles/'.self::ROLE_EXAM, ['role_name' => 'x'])->assertForbidden();
        $this->deleteJson('/api/v1/roles/'.self::ROLE_CUSTOM)->assertForbidden();
        $this->postJson('/api/v1/permissions', ['module_id' => 1, 'permission_code' => 'x.y', 'permission_name' => 'x', 'is_active' => 1])->assertForbidden();
        $this->getJson('/api/v1/user-activity-logs')->assertForbidden();
        self::assertFalse(DB::table('role_permissions')->where('role_id', self::ROLE_TECHNICAL)->where('permission_id', 5)->exists());

        $this->actingAsUser(self::ADMIN);
        $this->putJson('/api/v1/roles/'.self::ROLE_SUPER_ADMIN, ['is_active' => 0])->assertStatus(409);
        $this->putJson('/api/v1/roles/'.self::ROLE_SUPER_ADMIN, ['role_code' => 'renamed'])->assertStatus(409);
        $this->deleteJson('/api/v1/roles/'.self::ROLE_SUPER_ADMIN)->assertStatus(409);
        self::assertSame(1, (int) DB::table('roles')->where('role_code', 'super_admin')->value('is_active'));
        $this->getJson('/api/v1/user-activity-logs')->assertOk();
    }

    public function test_route_table_exposes_no_generic_write_for_users_user_roles_or_activity_logs(): void
    {
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! preg_match('#^api/v1/(users|user-roles|user-activity-logs)(/\{[^}]+\})?$#', $uri)) {
                continue;
            }
            self::assertSame([], array_values(array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE'])), $uri);
        }
        // Breeze routes/auth.php is not registered: POST /register only meets the SPA GET fallback.
        $this->post('/register', ['name' => 'x', 'email' => 'x@x.test', 'password' => 'Strong-Pass-2026', 'password_confirmation' => 'Strong-Pass-2026'])->assertStatus(405);
        self::assertFalse(Route::has('register'));
        self::assertSame(6, DB::table('users')->count());
    }

    // ── Fixture ───────────────────────────────────────────────────────────

    private function actingAsUser(int $userId): void
    {
        Sanctum::actingAs(User::findOrFail($userId));
    }

    private function newAccount(array $overrides = []): array
    {
        return array_merge([
            'username' => 'new.officer',
            'email' => 'New.Officer@alrowad.test',
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
            'account_status' => 'active',
        ], $overrides);
    }

    private function snapshot(): array
    {
        return [
            DB::table('users')->orderBy('user_id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('user_roles')->orderBy('user_role_id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('user_activity_logs')->orderBy('activity_log_id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    private function buildFixture(): void
    {
        if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'sqlite') {
            throw new \RuntimeException('Account administration fixture requires the isolated testing SQLite connection.');
        }
        Schema::dropAllTables();

        Schema::create('account_statuses', function (Blueprint $t): void {
            $t->integer('account_status_id')->primary();
            $t->string('status_code')->unique();
            $t->string('status_name')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t): void {
            $t->increments('user_id');
            $t->string('username', 80)->unique();
            $t->string('email', 150)->unique();
            $t->string('password_hash');
            $t->integer('account_status_id');
            $t->integer('student_id')->nullable()->unique();
            $t->integer('employee_id')->nullable()->unique();
            $t->integer('board_member_id')->nullable();
            $t->dateTime('last_login_at')->nullable();
            $t->dateTime('email_verified_at')->nullable();
            $t->integer('failed_login_attempts')->default(0);
            $t->integer('created_by_user_id')->nullable();
            $t->timestamps();
        });
        Schema::create('roles', function (Blueprint $t): void {
            $t->increments('role_id');
            $t->string('role_code', 80)->unique();
            $t->string('role_name', 150)->nullable();
            $t->string('description')->nullable();
            $t->boolean('is_system_role')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('system_modules', function (Blueprint $t): void {
            $t->increments('module_id');
            $t->string('module_code')->unique();
            $t->string('module_name')->nullable();
            $t->string('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('permissions', function (Blueprint $t): void {
            $t->increments('permission_id');
            $t->integer('module_id')->default(1);
            $t->string('permission_code', 120)->unique();
            $t->string('permission_name', 150)->nullable();
            $t->string('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('role_permissions', function (Blueprint $t): void {
            $t->increments('role_permission_id');
            $t->integer('role_id');
            $t->integer('permission_id');
            $t->timestamp('granted_at')->nullable();
            $t->unique(['role_id', 'permission_id']);
        });
        Schema::create('user_roles', function (Blueprint $t): void {
            $t->increments('user_role_id');
            $t->integer('user_id');
            $t->integer('role_id');
            $t->integer('assigned_by_user_id')->nullable();
            $t->timestamp('assigned_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unique(['user_id', 'role_id']);
        });
        Schema::create('user_activity_logs', function (Blueprint $t): void {
            $t->increments('activity_log_id');
            $t->integer('user_id');
            $t->string('module_code')->nullable();
            $t->string('action_code')->nullable();
            $t->text('description')->nullable();
            $t->string('ip_address')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('user_access_scopes', function (Blueprint $t): void {
            $t->increments('user_access_scope_id');
            $t->integer('user_id');
            $t->string('scope_type');
            $t->integer('scope_id')->nullable();
            $t->boolean('is_active')->default(true);
        });
        Schema::create('personal_access_tokens', function (Blueprint $t): void {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });

        DB::table('account_statuses')->insert([
            ['account_status_id' => 1, 'status_code' => 'active', 'status_name' => 'Active'],
            ['account_status_id' => 2, 'status_code' => 'disabled', 'status_name' => 'Disabled'],
            ['account_status_id' => 3, 'status_code' => 'locked', 'status_name' => 'Locked'],
        ]);
        DB::table('system_modules')->insert(['module_id' => 1, 'module_code' => 'users_permissions', 'module_name' => 'Users and Permissions']);

        foreach ([
            self::ROLE_SUPER_ADMIN => ['super_admin', true], self::ROLE_TECHNICAL => ['technical_team', true],
            self::ROLE_INSTRUCTOR => ['doctor_instructor', true], self::ROLE_EXAM => ['exam_officer', true],
            self::ROLE_DEAN => ['dean', true], self::ROLE_VP_SCI => ['vice_president_scientific', true],
            self::ROLE_STUDENT => ['student', true], self::ROLE_CUSTOM => ['custom_future_role', true],
            self::ROLE_HR => ['hr_officer', true], self::ROLE_INACTIVE_LIBRARIAN => ['librarian', false],
        ] as $id => [$code, $active]) {
            DB::table('roles')->insert(['role_id' => $id, 'role_code' => $code, 'role_name' => $code, 'is_system_role' => 1, 'is_active' => $active]);
        }
        foreach ([
            1 => 'technical_portal.access', 2 => 'user_accounts.view', 3 => 'user_accounts.manage',
            4 => 'users_permissions.view', 5 => 'users_permissions.manage', 6 => 'exams.view', 7 => 'grades.manage',
        ] as $id => $code) {
            DB::table('permissions')->insert(['permission_id' => $id, 'permission_code' => $code, 'permission_name' => $code]);
        }
        foreach ([
            [self::ROLE_SUPER_ADMIN, 2], [self::ROLE_SUPER_ADMIN, 3], [self::ROLE_SUPER_ADMIN, 4], [self::ROLE_SUPER_ADMIN, 5],
            [self::ROLE_TECHNICAL, 1], [self::ROLE_TECHNICAL, 2], [self::ROLE_TECHNICAL, 3],
            [self::ROLE_INSTRUCTOR, 7], [self::ROLE_EXAM, 6],
        ] as [$role, $permission]) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        }

        $hash = Hash::make('Existing-Pass-2026');
        foreach ([
            self::ADMIN => ['admin', null, null, self::ROLE_SUPER_ADMIN],
            self::TECH => ['tech', null, 40, self::ROLE_TECHNICAL],
            self::STAFF => ['instructor', null, 50, self::ROLE_INSTRUCTOR],
            self::DEAN => ['dean.user', null, 60, self::ROLE_DEAN],
            self::STUDENT => ['student.user', 77, null, self::ROLE_STUDENT],
            self::SECOND_TECH => ['tech2', null, 41, self::ROLE_TECHNICAL],
        ] as $id => [$username, $studentId, $employeeId, $role]) {
            DB::table('users')->insert([
                'user_id' => $id, 'username' => $username, 'email' => $username.'@alrowad.test', 'password_hash' => $hash,
                'account_status_id' => 1, 'student_id' => $studentId, 'employee_id' => $employeeId, 'failed_login_attempts' => 0,
            ]);
            DB::table('user_roles')->insert(['user_id' => $id, 'role_id' => $role, 'is_active' => 1]);
        }
    }
}
