<?php

namespace Tests\Feature;

use App\Models\{StudentUniversityEmail, UniversityEmailOperation, User};
use App\Support\UniversityEmailAccess as Access;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Http, Schema};
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MinistryReadFixture;
use Tests\TestCase;

class UniversityEmailPhase3Test extends TestCase
{
    use MinistryReadFixture;
    private const ROLE_MINISTRY = 2;
    private const ROOT = '/api/v1/technical/university-email/students/1';
    private array $boxes = [];
    private int $posts = 0;
    private ?string $failure = null;

    protected function setUp(): void
    {
        parent::setUp(); $this->buildFixture();
        Schema::create('system_modules', function (Blueprint $t) { $t->increments('module_id'); $t->string('module_code'); $t->string('module_name'); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('user_activity_logs', function (Blueprint $t) { $t->bigIncrements('activity_log_id'); $t->integer('user_id'); $t->string('module_code'); $t->string('action_code'); $t->text('description'); $t->string('ip_address')->nullable(); $t->timestamp('created_at')->nullable(); });
        DB::table('system_modules')->insert(['module_id' => 1, 'module_code' => 'users_permissions', 'module_name' => 'Synthetic', 'is_active' => true]);
        DB::table('roles')->insert(['role_id' => 10, 'role_code' => 'technical_team', 'role_name' => 'Technical', 'is_active' => true]);
        DB::table('user_roles')->insert(['user_id' => 8, 'role_id' => 10, 'is_active' => true]);
        $id = DB::table('permissions')->insertGetId(['permission_code' => 'technical_portal.access', 'permission_name' => 'Portal', 'module_id' => 1, 'is_active' => true], 'permission_id');
        DB::table('role_permissions')->insert(['role_id' => 10, 'permission_id' => $id]);
        DB::table('user_access_scopes')->insert(['user_id' => 8, 'scope_type' => 'university', 'scope_id' => 91, 'is_active' => true]);
        $this->artisan('university-email:enable-permissions --phase3')->assertExitCode(0);
        foreach (['000000_create_student_university_emails', '000001_add_university_email_provisioning', '000002_add_university_email_operation_cancellation', '000003_add_university_email_account_management'] as $file) (require database_path('migrations/2026_10_01_'.$file.'.php'))->up();
        DB::table('students')->where('student_id', 1)->update(['student_number' => 'R24011002', 'first_name' => 'أحمد', 'last_name' => 'اختبار']);
        config(['app.key' => 'base64:'.base64_encode(str_repeat('s', 32)), 'mailcow.provisioning_enabled' => true,
            'mailcow.contract_verified' => true, 'mailcow.write_api_key' => 'synthetic-key', 'mailcow.password_length' => 24]);
        Http::preventStrayRequests();
        Http::fake(function ($r) {
            $this->assertSame(0, DB::transactionLevel());
            if ($this->failure === 'offline') throw new \Illuminate\Http\Client\ConnectionException('DO NOT LEAK');
            if (str_contains($r->url(), '/get/alias/')) return Http::response([]);
            if (str_contains($r->url(), '/get/mailbox/')) return Http::response(array_values($this->boxes));
            $this->posts++;
            if ($this->failure === 'cancel_inflight') {
                $op = UniversityEmailOperation::where('active_slot', 1)->firstOrFail();
                try { app(\App\Services\UniversityEmailProvisioningService::class)->cancel(User::find(8), 1, $op->operation_id, $op->generation); $this->fail('Cancellation must lose after write-start'); }
                catch (\App\Exceptions\UniversityEmailException $e) { $this->assertSame('university_email_operation_not_cancellable', $e->errorCode); }
            }
            $edit = str_contains($r->url(), '/edit/mailbox');
            $address = $edit ? $r['items'][0] : $r['local_part'].'@'.$r['domain'];
            if (! $edit) $this->boxes[$address] = $this->box($address, $r['tags']);
            else {
                $attr = $r['attr'];
                if (isset($attr['tags'])) $this->boxes[$address]['tags'] = $attr['tags'];
                if (isset($attr['active'])) $this->boxes[$address]['active_int'] = (int) $attr['active'];
                if (isset($attr['force_pw_update'])) $this->boxes[$address]['attributes']['force_pw_update'] = (int) $attr['force_pw_update'];
            }
            if ($this->failure === 'lost_reply') throw new \Illuminate\Http\Client\ConnectionException('DO NOT LEAK PASSWORD');
            return Http::response([['type' => 'success', 'msg' => [$edit ? 'mailbox_modified' : 'mailbox_added', $address]]]);
        });
        Sanctum::actingAs(User::findOrFail(8));
    }

    private function box(string $address, array $tags = []): array
    {
        return ['username' => $address, 'domain' => 'alrowaduni.edu.sy', 'quota' => 50 * 1048576, 'quota_used' => 1048576,
            'active_int' => 1, 'attributes' => ['force_pw_update' => 1], 'tags' => $tags];
    }
    private function draft(): void { $this->putJson(self::ROOT.'/draft', ['english_first_name' => 'Ahmad', 'revision' => 0])->assertOk(); }
    private function executePassword(array $credentials): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(self::ROOT.'/provisioning/execute', array_diff_key($credentials, ['kind' => true]) + ['confirmed' => true]);
    }
    private function created(): array
    {
        $this->draft(); $c = $this->postJson(self::ROOT.'/provisioning/password', ['revision' => 1])->assertOk()->json('data');
        $this->executePassword($c)->assertOk(); return $c;
    }
    private function prepare(string $kind): array
    {
        return $this->postJson(self::ROOT.'/provisioning/prepare-account', ['kind' => $kind, 'revision' => StudentUniversityEmail::first()->revision, 'reason' => 'Synthetic operator reason', 'confirmed' => true])->assertOk()->json('data.operations.0');
    }
    private function executeAccount(array $op): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(self::ROOT.'/provisioning/execute-account', ['operation_id' => $op['operation_id'], 'generation' => $op['generation'], 'confirmed' => true]);
    }

    public function test_status_usage_missing_and_offline_preserve_confirmed_snapshot(): void
    {
        $this->created();
        $this->postJson(self::ROOT.'/provisioning/refresh-account')->assertOk()->assertJsonPath('data.remote_snapshot.usage_percent', 2)->assertJsonPath('data.check.status', 'verified');
        $before = StudentUniversityEmail::first()->remote_checked_at;
        $this->failure = 'offline';
        $this->postJson(self::ROOT.'/provisioning/refresh-account')->assertOk()->assertJsonPath('data.check.status', 'unavailable')->assertJsonPath('data.remote_snapshot.exists', true);
        $this->assertEquals($before, StudentUniversityEmail::first()->remote_checked_at);
        $this->failure = null; $this->boxes = [];
        $this->postJson(self::ROOT.'/provisioning/refresh-account')->assertOk()->assertJsonPath('data.check.status', 'missing')->assertJsonPath('data.remote_snapshot.exists', false);
    }
    public function test_suspend_activate_only_change_active_and_preserve_password_quota_tags(): void
    {
        $this->created(); $address = StudentUniversityEmail::first()->email_address; $original = $this->boxes[$address];
        $op = $this->prepare('suspend'); $this->executeAccount($op)->assertOk()->assertJsonPath('data.remote_snapshot.active', false);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/edit/mailbox') && $r['attr'] === ['active' => '0']);
        $this->assertSame($original, array_replace($this->boxes[$address], ['active_int' => 1]));
        $op = $this->prepare('activate'); $this->executeAccount($op)->assertOk()->assertJsonPath('data.remote_snapshot.active', true);
        $this->assertSame($original, $this->boxes[$address]);
        $this->assertDatabaseHas('student_university_emails', ['handover_status' => 'not_delivered']);
    }
    public function test_general_reset_uses_separate_permission_and_current_receipt(): void
    {
        $old = $this->created(); config(['mailcow.password_length' => 64]);
        $c = $this->postJson(self::ROOT.'/provisioning/reset-password', ['revision' => 1, 'reason' => 'Synthetic lost password'])->assertOk()->json('data');
        $this->assertSame(64, strlen($c['password']));
        $this->postJson(self::ROOT.'/provisioning/receipt', ['operation_id' => $old['operation_id'], 'generation' => $old['generation']])->assertStatus(409);
        $this->executePassword($c)->assertOk();
        $this->postJson(self::ROOT.'/provisioning/receipt', ['operation_id' => $c['operation_id'], 'generation' => $c['generation']])->assertOk()->assertJsonPath('data.receipt_purpose', 'password_reset');
        $this->assertStringNotContainsString($c['password'], DB::table('user_activity_logs')->pluck('description')->implode(' '));
        $this->assertStringNotContainsString($c['password'], UniversityEmailOperation::all()->toJson());
        $this->assertDatabaseHas('student_university_emails', ['handover_status' => 'not_delivered']);
    }
    public function test_link_requires_explicit_attestation_preserves_mailbox_and_supports_general_reset(): void
    {
        $this->draft(); $address = 'legacy.student@alrowaduni.edu.sy';
        $box = $this->box($address, ['existing-tag']); $box['quota'] = 200 * 1048576; $box['active_int'] = 0; $box['attributes']['force_pw_update'] = 0; $this->boxes[$address] = $box;
        $p = $this->postJson(self::ROOT.'/provisioning/preview-link', ['email_address' => $address])->assertOk()->json('data');
        $this->assertSame(0, $this->posts); $this->assertDatabaseCount('university_email_operations', 0);
        $input = ['kind' => 'link', 'revision' => 1, 'email_address' => $address, 'preview_proof' => $p['preview_proof'], 'reason' => 'Synthetic ownership record', 'ownership_confirmed' => true];
        $this->postJson(self::ROOT.'/provisioning/prepare-account', $input)->assertUnprocessable();
        $this->postJson(self::ROOT.'/provisioning/prepare-account', array_diff_key($input, ['ownership_confirmed' => true]) + ['confirmed' => true])->assertUnprocessable();
        $op = $this->postJson(self::ROOT.'/provisioning/prepare-account', $input + ['confirmed' => true])->assertOk()->json('data.operations.0');
        $this->executeAccount($op)->assertOk()->assertJsonPath('data.linkage_origin', 'linked');
        $this->assertSame($box, array_replace($this->boxes[$address], ['tags' => $box['tags']]));
        $this->assertContains('existing-tag', $this->boxes[$address]['tags']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/edit/mailbox') && array_keys($r['attr']) === ['tags']);
        $this->postJson(self::ROOT.'/provisioning/reissue', ['revision' => 2])->assertStatus(409);
        $c = $this->postJson(self::ROOT.'/provisioning/reset-password', ['revision' => 2, 'reason' => 'Verified reset'])->assertOk()->json('data');
        $this->executePassword($c)->assertOk(); $this->assertSame(0, $this->boxes[$address]['active_int']); $this->assertSame(200 * 1048576, $this->boxes[$address]['quota']);
    }
    public function test_link_preview_stale_and_existing_owner_denied_zero_writes(): void
    {
        $this->draft(); $address = 'legacy@alrowaduni.edu.sy'; $this->boxes[$address] = $this->box($address);
        $p = $this->postJson(self::ROOT.'/provisioning/preview-link', ['email_address' => $address])->assertOk()->json('data');
        $this->boxes[$address]['quota']++;
        $this->postJson(self::ROOT.'/provisioning/prepare-account', ['kind' => 'link', 'revision' => 1, 'email_address' => $address, 'preview_proof' => $p['preview_proof'], 'reason' => 'Synthetic proof', 'confirmed' => true, 'ownership_confirmed' => true])->assertStatus(409);
        $this->boxes[$address]['tags'] = ['alrowad-university-email:someone-else'];
        $this->postJson(self::ROOT.'/provisioning/preview-link', ['email_address' => $address])->assertStatus(409);
        $this->assertSame(0, $this->posts); $this->assertDatabaseCount('university_email_operations', 0);
    }
    public function test_permissions_scope_and_unsafe_input(): void
    {
        $this->created();
        foreach ([Access::RESET => 'reset-password', Access::SUSPEND => 'prepare-account', Access::ACTIVATE => 'prepare-account', Access::LINK => 'preview-link'] as $permission => $endpoint) {
            $id = DB::table('permissions')->where('permission_code', $permission)->value('permission_id'); DB::table('permissions')->where('permission_id', $id)->update(['is_active' => false]); Sanctum::actingAs(User::find(8));
            $body = match ($endpoint) { 'reset-password' => ['revision' => 1, 'reason' => 'test'], 'preview-link' => ['email_address' => 'legacy@alrowaduni.edu.sy'], default => ['kind' => $permission === Access::SUSPEND ? 'suspend' : 'activate', 'revision' => 1, 'reason' => 'test', 'confirmed' => true] };
            $this->postJson(self::ROOT.'/provisioning/'.$endpoint, $body)->assertForbidden(); DB::table('permissions')->where('permission_id', $id)->update(['is_active' => true]);
        }
        Sanctum::actingAs(User::find(8));
        $this->postJson(self::ROOT.'/provisioning/reset-password', ['revision' => 1, 'reason' => ' '])->assertUnprocessable();
        $this->postJson(self::ROOT.'/provisioning/preview-link', ['email_address' => 'bad@evil.invalid'])->assertUnprocessable();
        $this->postJson(self::ROOT.'/provisioning/prepare-account', ['kind' => 'activate', 'revision' => 1, 'reason' => 'test', 'confirmed' => true, 'password' => 'injected'])->assertUnprocessable();
        DB::table('user_access_scopes')->where('user_id', 8)->update(['is_active' => false]); Sanctum::actingAs(User::find(8));
        $this->postJson(self::ROOT.'/provisioning/refresh-account')->assertForbidden();
    }
    public function test_unowned_box_cannot_be_managed(): void
    {
        $this->created(); $address = StudentUniversityEmail::first()->email_address; $this->boxes[$address]['tags'] = [];
        $op = $this->prepare('suspend'); $this->executeAccount($op)->assertStatus(409);
        $this->assertSame(1, $this->posts);
    }
    public function test_lost_remote_reply_reconciles_without_another_post(): void
    {
        $this->created(); $op = $this->prepare('suspend'); $this->failure = 'lost_reply';
        $this->executeAccount($op)->assertStatus(409)->assertJsonPath('error_code', 'university_email_remote_uncertain');
        $this->assertDatabaseHas('university_email_operations', ['operation_id' => $op['operation_id'], 'status' => 'uncertain']);
        $this->executeAccount($op)->assertStatus(409); $this->assertSame(2, $this->posts);
        $this->failure = null; DB::table('university_email_operations')->where('operation_id', $op['operation_id'])->update(['updated_at' => now()->subMinutes(2)]);
        $this->postJson(self::ROOT.'/provisioning/reconcile', ['operation_id' => $op['operation_id']])->assertOk()->assertJsonPath('data.remote_snapshot.active', false);
        $this->assertSame(2, $this->posts);
    }
    public function test_cancel_pending_link_restores_draft_and_invalidates_old_worker(): void
    {
        $this->draft(); $address = 'legacy@alrowaduni.edu.sy'; $this->boxes[$address] = $this->box($address);
        $p = $this->postJson(self::ROOT.'/provisioning/preview-link', ['email_address' => $address])->assertOk()->json('data');
        $op = $this->postJson(self::ROOT.'/provisioning/prepare-account', ['kind' => 'link', 'revision' => 1, 'email_address' => $address, 'preview_proof' => $p['preview_proof'], 'reason' => 'test', 'confirmed' => true, 'ownership_confirmed' => true])->assertOk()->json('data.operations.0');
        $this->postJson(self::ROOT.'/provisioning/cancel', ['operation_id' => $op['operation_id'], 'generation' => 1, 'confirmed' => true])->assertOk();
        $this->assertDatabaseHas('student_university_emails', ['email_address' => 'ahmad.r24011002@alrowaduni.edu.sy', 'revision' => 3]);
        $this->executeAccount($op)->assertStatus(409); $this->assertSame(0, $this->posts);
    }
    public function test_phase2_and_account_operations_share_active_slot(): void
    {
        $this->created(); $op = $this->prepare('suspend');
        $this->postJson(self::ROOT.'/provisioning/reissue', ['revision' => 1])->assertStatus(409);
        $this->postJson(self::ROOT.'/provisioning/reset-password', ['revision' => 1, 'reason' => 'test'])->assertStatus(409);
        $this->postJson(self::ROOT.'/provisioning/prepare-account', ['kind' => 'activate', 'revision' => 1, 'reason' => 'test', 'confirmed' => true])->assertStatus(409);
        $this->failure = 'cancel_inflight'; $this->executeAccount($op)->assertOk();
    }
    public function test_missing_schema_blocks_phase3_but_preserves_phase2(): void
    {
        $this->created(); Schema::table('student_university_emails', fn (Blueprint $t) => $t->dropColumn('remote_snapshot'));
        $this->postJson(self::ROOT.'/provisioning/refresh-account')->assertStatus(503);
        $this->getJson(self::ROOT.'/provisioning')->assertOk()->assertJsonPath('data.account_schema_ready', false);
    }

    public function test_management_reconciliation_does_not_require_creation_permission(): void
    {
        $this->created(); $op = $this->prepare('suspend'); $this->failure = 'lost_reply';
        $this->executeAccount($op)->assertStatus(409);
        $this->failure = null;
        DB::table('permissions')->where('permission_code', Access::CREATE)->update(['is_active' => false]);
        Sanctum::actingAs(User::find(8));
        DB::table('university_email_operations')->where('operation_id', $op['operation_id'])->update(['updated_at' => now()->subMinutes(2)]);
        $this->postJson(self::ROOT.'/provisioning/reconcile', ['operation_id' => $op['operation_id']])->assertOk();
        $this->assertSame(2, $this->posts);
    }

    public function test_local_confirmation_failure_is_uncertain_and_reset_never_exposes_unknown_password(): void
    {
        $this->created();
        $c = $this->postJson(self::ROOT.'/provisioning/reset-password', ['revision' => 1, 'reason' => 'Synthetic reset rollback'])->assertOk()->json('data');
        DB::unprepared("CREATE TRIGGER synthetic_reset_audit_failure BEFORE INSERT ON user_activity_logs WHEN NEW.action_code = 'university_email.password_reset_confirmed' BEGIN SELECT RAISE(ABORT, 'synthetic'); END");
        $this->executePassword($c)->assertStatus(409)->assertJsonPath('error_code', 'university_email_local_confirmation_failed');
        $this->assertDatabaseHas('university_email_operations', ['operation_id' => $c['operation_id'], 'status' => 'uncertain']);
        $this->executePassword($c)->assertStatus(409); $this->assertSame(2, $this->posts);
        DB::unprepared('DROP TRIGGER synthetic_reset_audit_failure');
        DB::table('university_email_operations')->where('operation_id', $c['operation_id'])->update(['updated_at' => now()->subMinutes(2)]);
        $this->postJson(self::ROOT.'/provisioning/reconcile', ['operation_id' => $c['operation_id']])->assertOk()->assertJsonPath('data.credential_operation_id', null);
        $this->postJson(self::ROOT.'/provisioning/receipt', ['operation_id' => $c['operation_id'], 'generation' => $c['generation']])->assertStatus(409);
        $this->assertSame(2, $this->posts);
        $this->assertStringNotContainsString($c['password'], DB::table('user_activity_logs')->pluck('description')->implode(' '));
    }

    public function test_search_and_state_are_local_only_zero_write_reads(): void
    {
        $this->created(); $before = DB::table('user_activity_logs')->count(); $operations = UniversityEmailOperation::count();
        Http::fake(fn () => throw new \RuntimeException('Search/state must never call Mailcow'));
        $this->getJson('/api/v1/technical/university-email/students?q=R24011002')->assertOk();
        $this->getJson(self::ROOT.'/provisioning')->assertOk();
        $this->assertSame($before, DB::table('user_activity_logs')->count());
        $this->assertSame($operations, UniversityEmailOperation::count());
    }

    public function test_new_actions_deny_non_technical_and_inactive_accounts(): void
    {
        $this->created();
        DB::table('user_roles')->where('user_id', 8)->where('role_id', 10)->update(['is_active' => false]);
        Sanctum::actingAs(User::find(8));
        $this->postJson(self::ROOT.'/provisioning/refresh-account')->assertForbidden();
        $this->postJson(self::ROOT.'/provisioning/reset-password', ['revision' => 1, 'reason' => 'test'])->assertForbidden();
        DB::table('user_roles')->where('user_id', 8)->where('role_id', 10)->update(['is_active' => true]);
        DB::table('users')->where('user_id', 8)->update(['account_status_id' => 2]);
        Sanctum::actingAs(User::find(8));
        $this->postJson(self::ROOT.'/provisioning/preview-link', ['email_address' => 'legacy@alrowaduni.edu.sy'])->assertForbidden();
        $this->assertSame(1, $this->posts);
    }

    public function test_link_denies_address_reserved_for_another_student(): void
    {
        $this->draft(); $address = 'legacy@alrowaduni.edu.sy'; $this->boxes[$address] = $this->box($address);
        DB::table('students')->where('student_id', 2)->update(['student_number' => 'SYNTHETIC2']);
        $this->putJson('/api/v1/technical/university-email/students/2/draft', ['english_first_name' => 'Other', 'revision' => 0])->assertOk();
        StudentUniversityEmail::where('student_id', 2)->update(['email_address' => $address]);
        $this->postJson(self::ROOT.'/provisioning/preview-link', ['email_address' => $address])->assertStatus(409);
        $this->assertSame(0, $this->posts); $this->assertDatabaseCount('university_email_operations', 0);
    }

    public function test_delayed_refresh_cannot_overwrite_newer_operation_snapshot(): void
    {
        $this->created(); $op = $this->prepare('suspend'); $address = StudentUniversityEmail::first()->email_address;
        $old = $this->boxes[$address]; $intervened = false;
        Http::fake(function ($r) use (&$intervened, $op, $address, $old) {
            $this->assertSame(0, DB::transactionLevel());
            if ($r->method() === 'GET') {
                if (! $intervened) {
                    $intervened = true;
                    $this->executeAccount($op)->assertOk();
                    return Http::response([$old]); // The earlier read arrives after the mutation confirmed.
                }
                return Http::response(array_values($this->boxes));
            }
            $this->boxes[$address]['active_int'] = (int) $r['attr']['active'];
            return Http::response([['type' => 'success', 'msg' => ['mailbox_modified', $address]]]);
        });
        $this->postJson(self::ROOT.'/provisioning/refresh-account')->assertStatus(409)->assertJsonPath('error_code', 'university_email_stale');
        $this->assertFalse(StudentUniversityEmail::first()->remote_snapshot['active']);
    }
}
