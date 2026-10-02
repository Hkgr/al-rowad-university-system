<?php

namespace Tests\Feature;

use App\Models\{UniversityEmailOperation, User};
use Illuminate\Support\Facades\{DB, Http};
use Laravel\Sanctum\Sanctum;

/** Real HTTP/middleware, isolated SQLite, upstream-only fake; no live Mailcow. */
class UniversityEmailCreationRetryTest extends UniversityEmailPhase2Test
{
    private const API = '/api/v1/technical/university-email/students/1';

    private function prepareAttempt(): array
    {
        $this->putJson(self::API.'/draft', ['english_first_name' => 'Ahmad', 'revision' => 0])->assertOk();
        return $this->postJson(self::API.'/provisioning/password', ['revision' => 1])->assertOk()->json('data');
    }

    private function retryPayload(array $old, string $name = 'Corrected'): array
    {
        return ['english_first_name' => $name, 'operation_id' => $old['operation_id'], 'generation' => $old['generation'], 'confirmed' => true];
    }

    private function assertSafeRetry(string $status, int $operator = 8): void
    {
        Sanctum::actingAs(User::findOrFail($operator));
        $old = $this->prepareAttempt();
        if (in_array($status, ['failed', 'conflict'], true)) {
            if ($status === 'failed') $this->failure = 'preflight_timeout';
            else $this->aliases = [['address' => 'ahmad.r24011002@alrowaduni.edu.sy']];
            $this->postJson(self::API.'/provisioning/execute', array_diff_key($old, ['kind' => true]) + ['confirmed' => true])->assertConflict();
            $this->assertSame($status, UniversityEmailOperation::findOrFail($old['operation_id'])->status);
            $this->failure = null; $this->aliases = [];
        } else {
            DB::table('university_email_operations')->where('operation_id', $old['operation_id'])->update(['status' => $status]);
        }
        $this->getJson(self::API.'/provisioning')->assertOk()->assertJsonPath('data.creation.status', 'retry');
        $result = $this->postJson(self::API.'/retry-create', $this->retryPayload($old))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.creation.status', 'existing')->json('data');
        $this->assertSame('corrected.r24011002@alrowaduni.edu.sy', $result['email_address']);
        $this->assertSame(1, $this->writes);
        $this->assertDatabaseCount('university_email_operations', 2);
        $this->assertDatabaseHas('university_email_operations', ['operation_id' => $old['operation_id'], 'status' => 'cancelled',
            'generation' => 2, 'creation_slot' => null, 'active_slot' => null, 'write_started_at' => null, 'cancelled_by_user_id' => $operator]);
        $this->assertSame(1, DB::table('user_activity_logs')->where('action_code', 'university_email.operation_cancelled')->count());
        $this->assertSame(1, DB::table('university_email_operations')->where('creation_slot', 1)->count());
        $this->postJson(self::API.'/provisioning/execute', array_diff_key($old, ['kind' => true]) + ['confirmed' => true])
            ->assertConflict()->assertJsonPath('error_code', 'university_email_operation_stale');
        $this->postJson(self::API.'/retry-create', $this->retryPayload($old))->assertConflict();
        $this->assertSame(1, $this->writes);
        $this->assertDatabaseHas('student_university_emails', ['handover_status' => 'not_delivered']);
    }

    public function test_prepared_attempt_retries_without_exposing_cancellation_step(): void { $this->assertSafeRetry('prepared'); }
    public function test_failed_prewrite_attempt_can_retry(): void { $this->assertSafeRetry('failed'); }
    public function test_conflict_prewrite_attempt_can_retry(): void { $this->assertSafeRetry('conflict'); }
    public function test_preflight_old_worker_is_invalidated_by_retry(): void { $this->assertSafeRetry('preflight'); }
    public function test_super_admin_alone_can_safely_retry(): void
    {
        $admin = User::findOrFail(1);
        $this->assertSame(['super_admin'], $admin->effectiveRoles()->all());
        $this->assertSame([], $admin->effectivePermissions()->all());
        $this->assertSame(0, DB::table('user_access_scopes')->where('user_id', 1)->count());
        $this->assertSafeRetry('prepared', 1);
    }

    public function test_retry_preparation_rolls_back_cancellation_if_name_is_invalid(): void
    {
        $old = $this->prepareAttempt(); $audits = DB::table('user_activity_logs')->count();
        $this->postJson(self::API.'/retry-create', $this->retryPayload($old, 'Not valid 123'))->assertUnprocessable();
        $this->assertDatabaseHas('university_email_operations', ['operation_id' => $old['operation_id'], 'status' => 'prepared', 'generation' => 1]);
        $this->assertDatabaseCount('university_email_operations', 1);
        $this->assertSame($audits, DB::table('user_activity_logs')->count());
        Http::assertNothingSent();
    }

    public function test_retry_rejects_newer_generation_or_started_write_before_any_remote_call(): void
    {
        $old = $this->prepareAttempt();
        DB::table('university_email_operations')->increment('generation');
        $this->postJson(self::API.'/retry-create', $this->retryPayload($old))->assertConflict();
        DB::table('university_email_operations')->update(['generation' => 1, 'write_started_at' => now()]);
        $this->getJson(self::API.'/provisioning')->assertOk()->assertJsonPath('data.creation.status', 'verify');
        $this->postJson(self::API.'/retry-create', $this->retryPayload($old))->assertConflict();
        $this->assertDatabaseCount('university_email_operations', 1);
        Http::assertNothingSent();
    }

    private function uncertainAttempt(int $operator = 8): array
    {
        Sanctum::actingAs(User::findOrFail($operator)); $this->failure = 'lost_response';
        $this->postJson(self::API.'/create', ['english_first_name' => 'Ahmad', 'confirmed' => true])
            ->assertConflict()->assertJsonPath('error_code', 'university_email_remote_uncertain');
        $this->assertSame(1, $this->writes);
        $op = UniversityEmailOperation::firstOrFail();
        $this->assertSame('uncertain', $op->status); $this->assertNotNull($op->write_started_at);
        $this->failure = null; $this->travel(2)->minutes();
        return ['operation_id' => $op->operation_id, 'generation' => $op->generation];
    }

    public function test_read_only_check_confirms_existing_mailbox_without_second_post_or_credentials(): void
    {
        $this->uncertainAttempt();
        $this->postJson(self::API.'/creation-check')->assertOk()->assertJsonPath('data.creation.status', 'existing')
            ->assertJsonPath('data.provisioning_status', 'created')->assertJsonPath('data.credential_operation_id', null)->assertJsonMissingPath('data.credentials');
        $this->postJson(self::API.'/creation-check')->assertOk()->assertJsonPath('data.creation.status', 'existing');
        $this->assertSame(1, $this->writes);
        $this->assertDatabaseCount('university_email_operations', 1);
        $this->assertDatabaseHas('user_activity_logs', ['action_code' => 'university_email.creation_reconciled']);
    }

    public function test_admin_alone_can_reconcile_a_lost_response(): void
    {
        $this->uncertainAttempt(1);
        $this->postJson(self::API.'/creation-check')->assertOk()->assertJsonPath('data.creation.status', 'existing');
        $this->assertSame(1, $this->writes);
    }

    public function test_unresolved_uncertain_and_in_progress_never_authorize_duplicate_creation(): void
    {
        $old = $this->uncertainAttempt(); $this->boxes = [];
        foreach (['uncertain', 'in_progress'] as $status) {
            DB::table('university_email_operations')->update(['status' => $status]);
            $this->postJson(self::API.'/creation-check')->assertOk()->assertJsonPath('data.creation.status', 'verify');
            $this->postJson(self::API.'/create', ['english_first_name' => 'Ahmad', 'confirmed' => true])->assertConflict();
            $this->postJson(self::API.'/retry-create', $this->retryPayload($old))->assertConflict();
        }
        $this->assertSame(1, $this->writes); $this->assertDatabaseCount('university_email_operations', 1);
        $this->assertSame(0, DB::table('user_activity_logs')->where('action_code', 'university_email.operation_cancelled')->count());
        $this->assertNotNull(UniversityEmailOperation::firstOrFail()->write_started_at);
    }

    public function test_recent_worker_grace_and_remote_failure_remain_controlled_unresolved(): void
    {
        $this->uncertainAttempt();
        DB::table('university_email_operations')->update(['updated_at' => now()]);
        $before = count(Http::recorded());
        $this->postJson(self::API.'/creation-check')->assertOk()->assertJsonPath('data.creation.status', 'verify');
        $this->assertSame($before, count(Http::recorded()));
        $this->travel(2)->minutes(); $this->failure = 'preflight_timeout';
        $this->postJson(self::API.'/creation-check')->assertOk()->assertJsonPath('data.creation.status', 'verify');
        $this->assertSame(1, $this->writes);
    }

    public function test_retry_and_check_keep_technical_permissions_without_academic_scope_requirement(): void
    {
        $old = $this->prepareAttempt();
        foreach (['university_email.provision', 'university_email.view'] as $permission) {
            DB::table('permissions')->where('permission_code', $permission)->update(['is_active' => false]);
            $this->postJson(self::API.'/retry-create', $this->retryPayload($old))->assertForbidden();
            $this->postJson(self::API.'/creation-check')->assertForbidden();
            DB::table('permissions')->where('permission_code', $permission)->update(['is_active' => true]);
        }
        DB::table('user_access_scopes')->where('user_id', 8)->delete();
        $this->postJson(self::API.'/creation-check')->assertOk();
        $this->postJson(self::API.'/retry-create', $this->retryPayload($old))->assertOk();
        $requests = count(Http::recorded());
        $this->getJson('/api/v1/students')->assertForbidden();
        Sanctum::actingAs(User::findOrFail(2));
        $this->postJson(self::API.'/retry-create', $this->retryPayload($old))->assertForbidden();
        $this->postJson(self::API.'/creation-check')->assertForbidden();
        $this->assertSame($requests, count(Http::recorded()));
        $this->assertSame(1, $this->writes);
    }

    public function test_strict_input_and_get_state_are_read_only(): void
    {
        $old = $this->prepareAttempt(); $audits = DB::table('user_activity_logs')->count();
        $this->getJson(self::API.'/provisioning')->assertOk()->assertJsonPath('data.creation.status', 'retry');
        $this->postJson(self::API.'/retry-create', $this->retryPayload($old) + ['password' => 'untrusted'])->assertUnprocessable();
        $this->postJson(self::API.'/creation-check', ['confirmed' => true])->assertUnprocessable();
        $this->assertSame($audits, DB::table('user_activity_logs')->count());
        Http::assertNothingSent();
    }
}
