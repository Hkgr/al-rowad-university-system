<?php

namespace Tests\Feature;

use App\Models\{User, UniversityEmailOperation};
use App\Support\UniversityEmailAccess as Access;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Http, Schema};
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MinistryReadFixture;
use Tests\TestCase;

class UniversityEmailPhase2Test extends TestCase
{
    use MinistryReadFixture;
    private const ROLE_MINISTRY = 2;
    private const ROOT = '/api/v1/technical/university-email/students/1';
    private array $boxes = [];
    private array $aliases = [];
    private ?string $failure = null;
    private int $writes = 0;

    protected function setUp(): void
    {
        parent::setUp(); $this->buildFixture();
        Schema::create('system_modules', function (Blueprint $t) { $t->increments('module_id'); $t->string('module_code'); $t->string('module_name'); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('user_activity_logs', function (Blueprint $t) { $t->bigIncrements('activity_log_id'); $t->integer('user_id'); $t->string('module_code'); $t->string('action_code'); $t->text('description'); $t->string('ip_address')->nullable(); $t->timestamp('created_at')->nullable(); });
        DB::table('system_modules')->insert(['module_id' => 1, 'module_code' => 'users_permissions', 'module_name' => 'Synthetic permissions', 'is_active' => true]);
        DB::table('roles')->insert(['role_id' => 10, 'role_code' => 'technical_team', 'role_name' => 'Technical', 'is_active' => true]);
        DB::table('user_roles')->insert(['user_id' => 8, 'role_id' => 10, 'is_active' => true]);
        $portal = DB::table('permissions')->insertGetId(['permission_code' => 'technical_portal.access', 'permission_name' => 'Portal', 'module_id' => 1, 'is_active' => true], 'permission_id');
        DB::table('role_permissions')->insert(['role_id' => 10, 'permission_id' => $portal]);
        DB::table('user_access_scopes')->insert(['user_id' => 8, 'scope_type' => 'university', 'scope_id' => 91, 'is_active' => true]);
        $this->artisan('university-email:enable-permissions --phase2')->assertExitCode(0);
        (require database_path('migrations/2026_10_01_000000_create_student_university_emails.php'))->up();
        (require database_path('migrations/2026_10_01_000001_add_university_email_provisioning.php'))->up();
        (require database_path('migrations/2026_10_01_000002_add_university_email_operation_cancellation.php'))->up();
        DB::table('students')->where('student_id', 1)->update(['student_number' => 'R24011002', 'first_name' => 'أحمد', 'last_name' => 'اختبار']);
        config(['app.key' => 'base64:'.base64_encode(str_repeat('s', 32)), 'mailcow.provisioning_enabled' => true,
            'mailcow.contract_verified' => true, 'mailcow.write_api_key' => 'synthetic-write-key', 'mailcow.password_length' => 24]);
        Http::preventStrayRequests();
        Http::fake(function ($r) {
            $this->assertSame(0, DB::transactionLevel(), 'Remote calls must not hold DB locks');
            if (str_contains($r->url(), '/get/alias/all')) return Http::response($this->aliases);
            if (str_contains($r->url(), '/get/mailbox/')) {
                if ($this->failure === 'preflight_cancelled') {
                    $this->failure = null;
                    $op = UniversityEmailOperation::firstOrFail();
                    app(\App\Services\UniversityEmailProvisioningService::class)->cancel(User::findOrFail(8), 1, $op->operation_id, $op->generation);
                }
                if ($this->failure === 'preflight_replaced') {
                    // Deterministic interleaving, not a claim of MariaDB concurrency.
                    DB::table('university_email_operations')->increment('generation');
                    $this->failure = null;
                }
                return Http::response(str_contains($r->url(), '/all/') ? array_values($this->boxes) : ($this->boxes[rawurldecode(basename($r->url()))] ?? []));
            }
            $this->writes++;
            if ($this->failure === 'cancel_after_write_authority') {
                $op = UniversityEmailOperation::firstOrFail();
                try {
                    app(\App\Services\UniversityEmailProvisioningService::class)->cancel(User::findOrFail(8), 1, $op->operation_id, $op->generation);
                    $this->fail('Write authority must make cancellation impossible');
                } catch (\App\Exceptions\UniversityEmailException $failure) {
                    $this->assertSame('university_email_operation_not_cancellable', $failure->errorCode);
                }
            }
            if (in_array($this->failure, ['401', '403', '429'], true)) return Http::response(['secret' => $r['password']], (int) $this->failure);
            if ($this->failure === 'danger') return Http::response([['type' => 'danger', 'msg' => ['password '.$r['password']], 'log' => $r->data()]]);
            if ($this->failure === 'invalid') return Http::response('not-json', 200);
            $reset = str_contains($r->url(), '/edit/mailbox');
            $address = $reset ? $r['items'][0] : $r['local_part'].'@'.$r['domain'];
            $tags = $reset ? $r['attr']['tags'] : $r['tags'];
            $this->boxes[$address] = ['username' => $address, 'domain' => 'alrowaduni.edu.sy', 'quota' => 50 * 1048576,
                'active_int' => 1, 'attributes' => ['force_pw_update' => 1], 'tags' => $tags];
            if ($this->failure === 'quota') $this->boxes[$address]['quota'] = 50;
            if ($this->failure === 'lost_response') throw new \Illuminate\Http\Client\ConnectionException('SYNTHETIC SECRET MUST NOT LEAK');
            return Http::response([['type' => 'success', 'msg' => [$reset ? 'mailbox_modified' : 'mailbox_added', $address], 'log' => $r->data()]]);
        });
        Sanctum::actingAs(User::findOrFail(8));
    }

    private function credentials(): array
    {
        $this->putJson(self::ROOT.'/draft', ['english_first_name' => 'Ahmad', 'revision' => 0])->assertOk();
        return $this->postJson(self::ROOT.'/provisioning/password', ['revision' => 1])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->json('data');
    }
    private function execute(array $credentials): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(self::ROOT.'/provisioning/execute', array_diff_key($credentials, ['kind' => true]) + ['confirmed' => true]);
    }

    public function test_cancel_preserves_history_releases_slots_and_invalidates_old_credentials(): void
    {
        $c = $this->credentials();
        $this->postJson(self::ROOT.'/provisioning/cancel', ['operation_id' => $c['operation_id'], 'generation' => 1, 'confirmed' => true])
            ->assertOk()->assertJsonPath('data.draft_locked', false)->assertJsonPath('data.operations.0.status', 'cancelled');
        $this->assertDatabaseHas('university_email_operations', ['operation_id' => $c['operation_id'], 'generation' => 2,
            'creation_slot' => null, 'active_slot' => null, 'cancelled_by_user_id' => 8]);
        $this->getJson(self::ROOT)->assertOk()->assertJsonPath('data.draft_locked', false);
        $this->execute($c)->assertConflict()->assertJsonPath('error_code', 'university_email_operation_stale');
        Http::assertNothingSent();
        $this->putJson(self::ROOT.'/draft', ['english_first_name' => 'Corrected', 'revision' => 1])->assertOk();
        $next = $this->postJson(self::ROOT.'/provisioning/password', ['revision' => 2])->assertOk()->json('data');
        $this->assertNotSame($c['operation_id'], $next['operation_id']);
        $this->assertDatabaseCount('university_email_operations', 2);
        $this->assertSame(1, DB::table('university_email_operations')->where('creation_slot', 1)->count());
        $this->assertSame(1, DB::table('user_activity_logs')->where('action_code', 'university_email.operation_cancelled')->count());
        $this->execute($next)->assertOk();
        $this->assertSame(1, $this->writes);
    }

    public function test_failed_operation_can_be_cancelled_but_started_or_confirmed_operations_cannot(): void
    {
        foreach (['failed', 'prepared', 'conflict', 'preflight', 'in_progress', 'uncertain', 'confirmed'] as $state) {
            $this->travel(2)->minutes();
            if (! isset($c)) $c = $this->credentials();
            DB::table('university_email_operations')->where('operation_id', $c['operation_id'])->update([
                'status' => $state, 'generation' => 1, 'creation_slot' => 1, 'active_slot' => 1,
                'write_started_at' => in_array($state, ['in_progress', 'uncertain', 'confirmed']) ? now() : null]);
            $response = $this->postJson(self::ROOT.'/provisioning/cancel', ['operation_id' => $c['operation_id'], 'generation' => 1, 'confirmed' => true]);
            if (in_array($state, ['in_progress', 'uncertain', 'confirmed'])) $response->assertConflict()->assertJsonPath('error_code', 'university_email_operation_not_cancellable');
            else $response->assertOk()->assertJsonPath('data.operations.0.status', 'cancelled');
        }
        Http::assertNothingSent();
    }

    public function test_preflight_cancellation_stops_old_worker_without_overwriting_cancellation(): void
    {
        $c = $this->credentials(); $this->failure = 'preflight_cancelled';
        $this->execute($c)->assertConflict()->assertJsonPath('error_code', 'university_email_operation_stale');
        $this->assertSame(0, $this->writes);
        $this->assertDatabaseHas('university_email_operations', ['operation_id' => $c['operation_id'], 'status' => 'cancelled', 'generation' => 2, 'write_started_at' => null]);
        $this->assertDatabaseCount('university_email_receipts', 0);
    }

    public function test_write_authority_wins_interleaving_and_cancellation_is_denied(): void
    {
        $c = $this->credentials(); $this->failure = 'cancel_after_write_authority';
        $this->execute($c)->assertOk();
        $this->assertSame(1, $this->writes);
        $this->assertDatabaseHas('university_email_operations', ['status' => 'confirmed', 'generation' => 1]);
    }

    public function test_mailcow_name_is_server_student_name_and_password_supports_64_characters(): void
    {
        config(['mailcow.password_length' => 64]);
        DB::table('students')->where('student_id', 1)->update(['first_name' => 'عبد الرحمن محمد أحمد', 'last_name' => 'الاختبار الاصطناعي الطويل']);
        $c = $this->credentials();
        $this->assertSame(64, strlen($c['password']));
        $this->execute($c)->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), '/add/mailbox') && $r['name'] === 'عبد الرحمن محمد أحمد الاختبار الاصطناعي الطويل'
            && $r['local_part'] === 'ahmad.r24011002' && $r['password'] === $c['password'] && $r['password2'] === $c['password']);
        $this->postJson(self::ROOT.'/provisioning/execute', array_diff_key($c, ['kind' => true]) + ['confirmed' => true, 'name' => 'Untrusted'])->assertUnprocessable();
    }

    public function test_pending_reset_blocks_old_receipt_and_cancel_restores_confirmed_credentials_only(): void
    {
        $c = $this->credentials(); $this->execute($c)->assertOk();
        $reset = $this->postJson(self::ROOT.'/provisioning/reissue', ['revision' => 1])->assertOk()->json('data');
        $this->postJson(self::ROOT.'/provisioning/receipt', ['operation_id' => $c['operation_id'], 'generation' => 1])->assertConflict();
        $this->postJson(self::ROOT.'/provisioning/cancel', ['operation_id' => $reset['operation_id'], 'generation' => 1, 'confirmed' => true])->assertOk();
        $this->execute($reset)->assertConflict();
        $this->postJson(self::ROOT.'/provisioning/receipt', ['operation_id' => $c['operation_id'], 'generation' => 1])->assertOk();
        $this->assertDatabaseHas('student_university_emails', ['handover_status' => 'not_delivered']);
        $this->assertSame(1, $this->writes);
    }

    public function test_cancel_requires_permission_confirmation_and_current_generation(): void
    {
        $c = $this->credentials();
        $payload = ['operation_id' => $c['operation_id'], 'generation' => 1, 'confirmed' => true];
        $this->postJson(self::ROOT.'/provisioning/cancel', $payload + ['name' => 'Untrusted'])->assertUnprocessable();
        $this->postJson(self::ROOT.'/provisioning/cancel', array_replace($payload, ['confirmed' => false]))->assertUnprocessable();
        $this->postJson(self::ROOT.'/provisioning/cancel', array_replace($payload, ['generation' => 2]))->assertConflict();
        $id = DB::table('permissions')->where('permission_code', Access::CREATE)->value('permission_id');
        DB::table('role_permissions')->where('permission_id', $id)->delete();
        $this->postJson(self::ROOT.'/provisioning/cancel', $payload)->assertForbidden();
        $this->assertDatabaseHas('university_email_operations', ['status' => 'prepared', 'generation' => 1, 'creation_slot' => 1]);
        Http::assertNothingSent();
    }

    public function test_cancel_audit_failure_rolls_back_slots_generation_and_draft_unlock(): void
    {
        $c = $this->credentials();
        DB::statement("CREATE TRIGGER synthetic_cancel_audit_failure BEFORE INSERT ON user_activity_logs WHEN NEW.action_code = 'university_email.operation_cancelled' BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        $this->postJson(self::ROOT.'/provisioning/cancel', ['operation_id' => $c['operation_id'], 'generation' => 1, 'confirmed' => true])->assertStatus(500);
        $this->assertDatabaseHas('university_email_operations', ['operation_id' => $c['operation_id'], 'status' => 'prepared', 'generation' => 1,
            'creation_slot' => 1, 'active_slot' => 1, 'cancelled_at' => null]);
        $this->getJson(self::ROOT)->assertOk()->assertJsonPath('data.draft_locked', true);
        Http::assertNothingSent();
    }

    public function test_complete_real_http_flow_and_idempotent_receipt_without_delivery_claim(): void
    {
        $c = $this->credentials();
        $this->assertSame(24, strlen($c['password']));
        $this->execute($c)->assertOk()->assertJsonPath('data.provisioning_status', 'created');
        $this->assertSame(1, $this->writes);
        $payload = ['operation_id' => $c['operation_id'], 'generation' => $c['generation']];
        $a = $this->postJson(self::ROOT.'/provisioning/receipt', $payload)->assertOk()->json('data');
        $b = $this->postJson(self::ROOT.'/provisioning/receipt', $payload)->assertOk()->json('data');
        $this->assertSame($a, $b);
        $this->assertDatabaseCount('university_email_receipts', 1);
        $this->assertDatabaseHas('student_university_emails', ['student_id' => 1, 'handover_status' => 'not_delivered']);
        $this->execute($c)->assertConflict(); $this->assertSame(1, $this->writes);
        foreach (['student_university_emails', 'university_email_operations', 'university_email_receipts', 'user_activity_logs'] as $table) {
            $stored = json_encode(DB::table($table)->get());
            $this->assertStringNotContainsString($c['password'], $stored);
            $this->assertStringNotContainsString($c['credential_proof'], $stored);
        }
        $this->assertStringNotContainsString('password', json_encode($a));
    }

    public function test_separate_permissions_actual_scope_and_unauthorized_identities(): void
    {
        $this->putJson(self::ROOT.'/draft', ['english_first_name' => 'Ahmad', 'revision' => 0])->assertOk();
        $id = DB::table('permissions')->where('permission_code', Access::CREATE)->value('permission_id');
        DB::table('role_permissions')->where('permission_id', $id)->delete();
        $this->postJson(self::ROOT.'/provisioning/password', ['revision' => 1])->assertForbidden();
        Sanctum::actingAs(User::findOrFail(1));
        $this->postJson(self::ROOT.'/provisioning/password', ['revision' => 1])->assertForbidden();
        Sanctum::actingAs(User::findOrFail(8));
        DB::table('user_access_scopes')->where('user_id', 8)->delete();
        $this->getJson(self::ROOT.'/provisioning')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_disabled_writes_and_missing_schema_fail_closed_but_phase1_remains_available(): void
    {
        config(['mailcow.provisioning_enabled' => false]);
        $this->putJson(self::ROOT.'/draft', ['english_first_name' => 'Ahmad', 'revision' => 0])->assertOk();
        $this->postJson(self::ROOT.'/provisioning/password', ['revision' => 1])->assertStatus(503);
        Schema::drop('university_email_receipts');
        $this->getJson(self::ROOT.'/provisioning')->assertStatus(503);
        $this->getJson('/api/v1/technical/university-email/students')->assertOk();
        Http::assertNothingSent();
    }

    public function test_freeze_stale_generation_proof_tampering_and_receipt_before_confirmation(): void
    {
        $c = $this->credentials();
        $this->putJson(self::ROOT.'/draft', ['english_first_name' => 'Other', 'revision' => 1])->assertConflict()->assertJsonPath('error_code', 'university_email_identity_frozen');
        $this->postJson(self::ROOT.'/provisioning/receipt', ['operation_id' => $c['operation_id'], 'generation' => 1])->assertConflict();
        $next = $this->postJson(self::ROOT.'/provisioning/password', ['revision' => 1])->assertOk()->json('data');
        $this->assertNotSame($c['password'], $next['password']);
        $this->execute($c)->assertConflict();
        $next['password'] = str_repeat('X', 24);
        $this->execute($next)->assertConflict();
        Http::assertNothingSent();
    }

    public function test_existing_mailbox_or_alias_is_never_adopted_or_reset(): void
    {
        $c = $this->credentials();
        $this->aliases = [['address' => 'ahmad.r24011002@alrowaduni.edu.sy']];
        $this->execute($c)->assertConflict()->assertJsonPath('error_code', 'university_email_address_conflict');
        $this->assertSame(0, $this->writes);
        $this->assertDatabaseHas('student_university_emails', ['provisioning_status' => 'draft']);
        $this->assertDatabaseHas('university_email_operations', ['status' => 'conflict']);
    }

    public function test_existing_foreign_mailbox_and_unowned_reconciliation_are_denied(): void
    {
        $c = $this->credentials();
        $address = 'ahmad.r24011002@alrowaduni.edu.sy';
        $this->boxes[$address] = ['username' => $address, 'domain' => 'alrowaduni.edu.sy', 'quota' => 50 * 1048576,
            'active_int' => 1, 'attributes' => ['force_pw_update' => 1], 'tags' => ['foreign']];
        $this->execute($c)->assertConflict()->assertJsonPath('error_code', 'university_email_address_conflict');
        $this->assertSame(0, $this->writes);
        DB::table('university_email_operations')->update(['status' => 'uncertain', 'active_slot' => 1, 'write_started_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2)]);
        $this->postJson(self::ROOT.'/provisioning/reconcile', ['operation_id' => $c['operation_id']])->assertConflict()->assertJsonPath('error_code', 'university_email_manual_review_required');
        $this->assertDatabaseHas('student_university_emails', ['provisioning_status' => 'draft']);
    }

    public function test_abandoned_preflight_is_recoverable_without_remote_write(): void
    {
        $c = $this->credentials();
        DB::table('university_email_operations')->update(['status' => 'preflight', 'updated_at' => now()->subMinutes(2)]);
        $this->postJson(self::ROOT.'/provisioning/reconcile', ['operation_id' => $c['operation_id']])->assertOk()->assertJsonPath('data.operations.0.status', 'failed');
        $next = $this->postJson(self::ROOT.'/provisioning/password', ['revision' => 1])->assertOk()->json('data');
        $this->assertSame($c['operation_id'], $next['operation_id']);
        $this->assertSame(2, $next['generation']);
        $this->execute($c)->assertConflict(); Http::assertNothingSent();
    }

    public function test_old_preflight_worker_cannot_write_or_overwrite_new_generation(): void
    {
        $c = $this->credentials(); $this->failure = 'preflight_replaced';
        $this->execute($c)->assertConflict()->assertJsonPath('error_code', 'university_email_operation_stale');
        $this->assertSame(0, $this->writes);
        $this->assertDatabaseHas('university_email_operations', ['operation_id' => $c['operation_id'], 'generation' => 2, 'status' => 'preflight']);
        $this->assertDatabaseCount('university_email_receipts', 0);
    }

    public function test_uncertain_write_read_only_reconciliation_and_limited_reset(): void
    {
        $c = $this->credentials(); $this->failure = 'lost_response';
        $this->execute($c)->assertConflict()->assertJsonPath('error_code', 'university_email_remote_uncertain');
        $this->assertSame(1, $this->writes);
        $this->execute($c)->assertConflict();
        DB::table('university_email_operations')->update(['updated_at' => now()->subMinutes(2)]);
        $this->postJson(self::ROOT.'/provisioning/reconcile', ['operation_id' => $c['operation_id']])->assertOk()
            ->assertJsonPath('data.provisioning_status', 'created')->assertJsonPath('data.credential_operation_id', null);
        $this->postJson(self::ROOT.'/provisioning/receipt', ['operation_id' => $c['operation_id'], 'generation' => 1])->assertConflict();
        $this->failure = null;
        $reset = $this->postJson(self::ROOT.'/provisioning/reissue', ['revision' => 1])->assertOk()->json('data');
        $this->execute($reset)->assertOk()->assertJsonPath('data.credential_operation_id', $reset['operation_id']);
        $this->assertSame(2, $this->writes);
        $this->postJson(self::ROOT.'/provisioning/receipt', ['operation_id' => $reset['operation_id'], 'generation' => 1])->assertOk();
    }

    public function test_remote_failure_matrix_never_leaks_or_allows_receipts(): void
    {
        foreach (['danger', 'invalid', '401', '403', '429', 'quota'] as $case) {
            $this->travel(2)->minutes(); // independent operation windows; retain HTTP throttle middleware
            // New synthetic student/draft per immutable operation identity.
            $student = (array) DB::table('students')->where('student_id', 1)->first();
            $id = 100 + $this->writes;
            DB::table('students')->insert(array_replace($student, ['student_id' => $id, 'student_number' => 'S'.$id]));
            $root = '/api/v1/technical/university-email/students/'.$id;
            $this->putJson($root.'/draft', ['english_first_name' => 'Test', 'revision' => 0])->assertOk();
            $c = $this->postJson($root.'/provisioning/password', ['revision' => 1])->assertOk()->json('data');
            $this->failure = $case;
            $response = $this->postJson($root.'/provisioning/execute', array_diff_key($c, ['kind' => true]) + ['confirmed' => true])->assertConflict();
            $this->assertStringNotContainsString($c['password'], $response->getContent());
            $this->assertStringNotContainsString('synthetic-write-key', $response->getContent());
            $this->postJson($root.'/provisioning/receipt', ['operation_id' => $c['operation_id'], 'generation' => 1])->assertConflict();
        }
        $this->assertDatabaseCount('university_email_receipts', 0);
    }

    public function test_local_commit_failure_keeps_remote_mailbox_and_recovers_without_second_post(): void
    {
        $c = $this->credentials();
        DB::statement("CREATE TRIGGER synthetic_audit_failure BEFORE INSERT ON user_activity_logs WHEN NEW.action_code = 'university_email.create_confirmed' BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        $this->execute($c)->assertConflict()->assertJsonPath('error_code', 'university_email_local_confirmation_failed');
        $this->assertSame(1, $this->writes); $this->assertCount(1, $this->boxes);
        $this->assertDatabaseHas('student_university_emails', ['provisioning_status' => 'draft']);
        DB::statement('DROP TRIGGER synthetic_audit_failure');
        DB::table('university_email_operations')->update(['updated_at' => now()->subMinutes(2)]);
        $this->postJson(self::ROOT.'/provisioning/reconcile', ['operation_id' => $c['operation_id']])->assertOk();
        $this->assertSame(1, $this->writes);
    }

    public function test_student_reads_only_own_confirmed_address_and_never_draft_or_password(): void
    {
        $c = $this->credentials(); $this->execute($c)->assertOk();
        DB::table('roles')->insert(['role_id' => 11, 'role_code' => 'student', 'role_name' => 'Student', 'is_active' => true]);
        DB::table('user_roles')->insert(['user_id' => 1, 'role_id' => 11, 'is_active' => true]);
        DB::table('users')->where('user_id', 1)->update(['student_id' => 1]);
        Sanctum::actingAs(User::findOrFail(1));
        $response = $this->getJson('/api/v1/student/university-email')->assertOk()->assertJsonPath('data.email_address', 'ahmad.r24011002@alrowaduni.edu.sy');
        $this->assertStringNotContainsString($c['password'], $response->getContent());
        $this->getJson('/api/v1/student/university-email?student_id=2')->assertUnprocessable();
        $this->getJson(self::ROOT.'/provisioning')->assertForbidden();
    }

    public function test_export_isolated_phase2_browser_fixture_when_requested(): void
    {
        if (! ($directory = getenv('UNIVERSITY_EMAIL_PHASE2_BROWSER_DIR'))) { $this->assertTrue(true); return; }
        $directory = realpath($directory);
        $this->assertNotFalse($directory);
        $this->assertStringStartsWith(strtolower(realpath(sys_get_temp_dir())).DIRECTORY_SEPARATOR, strtolower($directory).DIRECTORY_SEPARATOR);
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        // Long synthetic identity/address fixtures; never production student data.
        DB::table('students')->where('student_id', 1)->update(['first_name' => 'عبد الرحمن محمد أحمد الاختبار الاصطناعي الطويل', 'last_name' => 'الطالب ذو الاسم العربي الطويل لاختبار الإيصال']);
        DB::table('students')->where('student_id', 2)->update(['student_number' => 'SYNTHETIC2']);
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id(); $t->string('tokenable_type'); $t->unsignedBigInteger('tokenable_id'); $t->string('name');
            $t->string('token', 64)->unique(); $t->text('abilities')->nullable(); $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable(); $t->timestamps();
        });
        DB::table('roles')->insert(['role_id' => 11, 'role_code' => 'student', 'role_name' => 'Student', 'is_active' => true]);
        DB::table('user_roles')->insert(['user_id' => 1, 'role_id' => 11, 'is_active' => true]);
        DB::table('users')->where('user_id', 1)->update(['student_id' => 1]);
        $actors = [];
        foreach (['technical' => 8, 'unauthorized' => 2, 'student' => 1] as $name => $id) {
            $user = User::findOrFail($id);
            $actors[$name] = ['identity' => app(\App\Services\UserIdentityService::class)->payload($user),
                'token' => $user->createToken('isolated-email-phase2-browser')->plainTextToken];
        }
        file_put_contents($directory.'/identities.json', json_encode($actors, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        DB::statement('VACUUM INTO ?', [$directory.'/email.sqlite']);
    }
}
