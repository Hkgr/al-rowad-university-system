<?php

namespace Tests\Feature;

use App\Models\UniversityEmailOperation;
use Illuminate\Support\Facades\{DB, Http};

/** Real HTTP/middleware, synthetic SQLite, upstream-only fake. No production data. */
class UniversityEmailReconciliationTest extends UniversityEmailPhase2Test
{
    private const API = '/api/v1/technical/university-email/students/1';

    private function lostCreate(): UniversityEmailOperation
    {
        $this->failure = 'lost_response';
        $this->postJson(self::API.'/create', ['english_first_name' => 'Synthetic', 'confirmed' => true])
            ->assertConflict()->assertJsonPath('error_code', 'university_email_remote_uncertain');
        $this->failure = null;
        $this->assertSame(1, $this->writes);
        return UniversityEmailOperation::firstOrFail();
    }

    public function test_uncertain_positive_read_confirms_before_grace_without_password_or_second_write(): void
    {
        $op = $this->lostCreate();
        $initial = $this->getJson(self::API.'/provisioning')->assertOk()->json('data');
        $this->postJson(self::API.'/creation-check')->assertOk()
            ->assertJsonPath('data.provisioning_status', 'created')
            ->assertJsonPath('data.creation.status', 'existing')
            ->assertJsonPath('data.reconciliation.status', 'ready')
            ->assertJsonPath('data.pending_operation', null)
            ->assertJsonPath('data.credential_state.status', 'lost_after_reconciliation')
            ->assertJsonPath('data.credential_operation_id', null)->assertJsonMissingPath('data.credentials');
        $this->assertSame('waiting', $initial['reconciliation']['status']);
        $this->assertSame('fast_read_check', $initial['reconciliation']['mode']);
        $this->assertSame(2, $initial['reconciliation']['retry_after_seconds']);
        $this->assertNull($initial['reconciliation']['ready_at']);
        $this->postJson(self::API.'/provisioning/receipt', ['operation_id' => $op->operation_id, 'generation' => $op->generation])->assertConflict();
        $this->assertSame(1, $this->writes);
        $this->assertDatabaseHas('university_email_operations', ['operation_id' => $op->operation_id, 'status' => 'confirmed']);
        $this->assertDatabaseCount('university_email_receipts', 0);
    }

    public function test_early_absence_preserves_uncertain_slots_then_later_presence_confirms(): void
    {
        $op = $this->lostCreate(); $box = $this->boxes; $this->boxes = [];
        $auditCount = DB::table('user_activity_logs')->count();
        foreach ([0, 2, 3] as $seconds) {
            $this->travel($seconds)->seconds();
            $response = $this->postJson(self::API.'/creation-check')->assertOk()
                ->assertJsonPath('data.reconciliation.status', 'waiting')
                ->assertJsonPath('data.reconciliation.mode', 'fast_read_check');
            $this->assertGreaterThan(0, $response->json('data.reconciliation.retry_after_seconds'));
            $this->assertDatabaseHas('university_email_operations', ['operation_id' => $op->operation_id,
                'status' => 'uncertain', 'creation_slot' => 1, 'active_slot' => 1]);
            $this->assertSame($auditCount, DB::table('user_activity_logs')->count());
        }
        $this->boxes = $box;
        $this->postJson(self::API.'/creation-check')->assertOk()->assertJsonPath('data.creation.status', 'existing');
        $this->assertSame(1, $this->writes);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/get/mailbox/'));
    }

    public function test_wrong_marker_and_context_never_confirm_or_release_started_source(): void
    {
        $op = $this->lostCreate(); $original = $this->boxes;
        foreach (['marker', 'address', 'domain', 'quota', 'active', 'force'] as $wrong) {
            $this->boxes = $original; $address = array_key_first($original);
            if ($wrong === 'marker') $this->boxes[$address]['tags'] = ['alrowad-university-email:wrong'];
            if ($wrong === 'address') $this->boxes[$address]['username'] = 'other@alrowaduni.edu.sy';
            if ($wrong === 'domain') $this->boxes[$address]['domain'] = 'wrong.invalid';
            if ($wrong === 'quota') $this->boxes[$address]['quota'] = 25 * 1048576;
            if ($wrong === 'active') $this->boxes[$address]['active_int'] = 0;
            if ($wrong === 'force') $this->boxes[$address]['attributes']['force_pw_update'] = 0;
            $this->postJson(self::API.'/creation-check')->assertOk()->assertJsonPath('data.creation.status', 'verify')
                ->assertJsonPath('data.credential_state.status', 'none');
            $this->assertDatabaseHas('university_email_operations', ['operation_id' => $op->operation_id,
                'status' => 'uncertain', 'creation_slot' => 1, 'active_slot' => 1]);
        }
        $this->assertSame(1, $this->writes);
    }

    public function test_waiting_deadline_is_stable_and_post_grace_failure_is_unresolved(): void
    {
        $this->freezeTime();
        $op = $this->lostCreate(); $this->boxes = [];
        // Live workers retain their original grace; started uncertain creates have a separate policy.
        DB::table('university_email_operations')->where('operation_id', $op->operation_id)->update(['status' => 'in_progress']);
        $readyAt = $this->getJson(self::API.'/provisioning')->assertOk()->json('data.reconciliation.ready_at');
        $this->travel(10)->seconds();
        $this->postJson(self::API.'/creation-check')->assertOk()
            ->assertJsonPath('data.reconciliation.retry_after_seconds', 50)->assertJsonPath('data.reconciliation.ready_at', $readyAt);
        $this->travel(50)->seconds();
        $this->postJson(self::API.'/creation-check')->assertOk()
            ->assertJsonPath('data.reconciliation.status', 'unresolved')->assertJsonPath('data.reconciliation.retry_after_seconds', 0);
        $this->assertSame($op->updated_at->toDateTimeString(), $op->fresh()->updated_at->toDateTimeString());
        $this->assertSame(1, $this->writes);
    }

    public function test_active_worker_waits_without_remote_read_or_automatic_preflight_cancellation(): void
    {
        $this->freezeTime();
        $this->putJson(self::API.'/draft', ['english_first_name' => 'Synthetic', 'revision' => 0])->assertOk();
        $c = $this->postJson(self::API.'/provisioning/password', ['revision' => 1])->assertOk()->json('data');
        foreach (['preflight', 'in_progress'] as $status) {
            DB::table('university_email_operations')->update(['status' => $status, 'updated_at' => now(), 'active_slot' => 1,
                'write_started_at' => $status === 'in_progress' ? now() : null]);
            $this->postJson(self::API.'/creation-check')->assertOk()->assertJsonPath('data.reconciliation.status', 'waiting')
                ->assertJsonPath('data.reconciliation.mode', 'worker_grace')
                ->assertJsonPath('data.reconciliation.retry_after_seconds', 60);
        }
        $this->travel(61)->seconds();
        DB::table('university_email_operations')->update(['status' => 'preflight', 'write_started_at' => null]);
        $this->postJson(self::API.'/creation-check')->assertOk()->assertJsonPath('data.reconciliation.status', 'unresolved');
        $this->assertDatabaseHas('university_email_operations', ['operation_id' => $c['operation_id'], 'status' => 'preflight', 'active_slot' => 1]);
        Http::assertNothingSent();
    }

    public function test_fast_negative_reads_keep_slots_clock_and_audit_unchanged_without_remote_writes(): void
    {
        $op = $this->lostCreate(); $this->boxes = [];
        $audit = DB::table('user_activity_logs')->count();
        $requestsBefore = Http::recorded()->count();
        foreach ([0, 2, 3, 5, 8] as $seconds) {
            $this->travel($seconds)->seconds();
            $this->postJson(self::API.'/creation-check')->assertOk()
                ->assertJsonPath('data.reconciliation.mode', 'fast_read_check')
                ->assertJsonPath('data.reconciliation.retry_after_seconds', 2)
                ->assertJsonPath('data.creation.status', 'verify')
                ->assertJsonPath('data.credential_state.status', 'none');
            $this->assertDatabaseHas('university_email_operations', ['operation_id' => $op->operation_id,
                'status' => 'uncertain', 'active_slot' => 1, 'creation_slot' => 1]);
            $this->assertSame($op->updated_at->toDateTimeString(), $op->fresh()->updated_at->toDateTimeString());
            $this->assertSame($audit, DB::table('user_activity_logs')->count());
        }
        $recoveryRequests = Http::recorded()->slice($requestsBefore);
        $this->assertCount(5, $recoveryRequests);
        $this->assertTrue($recoveryRequests->every(fn ($pair) => $pair[0]->method() === 'GET'));
        $this->assertSame(1, $this->writes); // Original create only; all recovery checks are upstream reads.
        $this->assertDatabaseCount('university_email_receipts', 0);
    }

    public function test_explicit_reset_after_reconciliation_returns_new_credentials_and_receipt(): void
    {
        (require database_path('migrations/2026_10_01_000003_add_university_email_account_management.php'))->up();
        $this->artisan('university-email:enable-permissions --phase3')->assertExitCode(0);
        $create = $this->lostCreate();
        $state = $this->postJson(self::API.'/creation-check')->assertOk()
            ->assertJsonPath('data.credential_operation_id', null)->json('data');
        $this->postJson(self::API.'/provisioning/receipt', ['operation_id' => $create->operation_id, 'generation' => $create->generation])->assertConflict();
        $reset = $this->postJson(self::API.'/reset-password-now', ['revision' => $state['revision'],
            'confirmed' => true, 'reason' => 'Synthetic explicit recovery'])->assertOk()->json('data');
        $this->assertSame('password_reset', $reset['credentials']['kind']);
        $this->assertNotSame($create->operation_id, $reset['credentials']['operation_id']);
        $this->assertSame(24, strlen($reset['credentials']['password']));
        $this->postJson(self::API.'/provisioning/receipt', ['operation_id' => $reset['credentials']['operation_id'],
            'generation' => $reset['credentials']['generation']])->assertOk()->assertJsonPath('data.receipt_purpose', 'password_reset');
        $this->assertSame(2, $this->writes); // One original creation, one explicit reset, no recovery write.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/edit/mailbox') && $r['attr']['password'] === $reset['credentials']['password']);
        $this->assertDatabaseCount('university_email_receipts', 1);
        $this->assertDatabaseHas('student_university_emails', ['handover_status' => 'not_delivered']);
    }

    public function test_changed_generation_during_early_read_cannot_confirm_an_old_proof(): void
    {
        $op = $this->lostCreate(); $this->failure = 'preflight_replaced';
        $this->postJson(self::API.'/creation-check')->assertOk()->assertJsonPath('data.creation.status', 'verify');
        $this->assertDatabaseHas('university_email_operations', ['operation_id' => $op->operation_id,
            'status' => 'uncertain', 'generation' => $op->generation + 1, 'creation_slot' => 1, 'active_slot' => 1]);
        $this->assertSame(1, $this->writes);
    }

    public function test_loss_semantic_requires_exact_persisted_audit_not_null_or_bounded_history(): void
    {
        (require database_path('migrations/2026_10_01_000003_add_university_email_account_management.php'))->up();
        $op = $this->lostCreate();
        $this->postJson(self::API.'/creation-check')->assertOk()->assertJsonPath('data.credential_state.status', 'lost_after_reconciliation');
        $entry = DB::table('user_activity_logs')->where('action_code', 'university_email.creation_reconciled')->first();
        $original = json_decode($entry->description, true, flags: JSON_THROW_ON_ERROR);
        foreach (['operation_id', 'record_id', 'student_id', 'revision', 'generation'] as $wrong) {
            $changed = $original; $changed[$wrong] = $wrong === 'operation_id' ? 'wrong-operation' : $original[$wrong] + 1;
            DB::table('user_activity_logs')->where('activity_log_id', $entry->activity_log_id)->update(['description' => json_encode($changed, JSON_THROW_ON_ERROR)]);
            $this->getJson(self::API.'/provisioning')->assertOk()->assertJsonPath('data.credential_state.status', 'none');
        }
        DB::table('user_activity_logs')->where('activity_log_id', $entry->activity_log_id)->update(['description' => 'Synthetic non-JSON historical entry']);
        $this->getJson(self::API.'/provisioning')->assertOk()->assertJsonPath('data.credential_state.status', 'none');
        DB::table('user_activity_logs')->where('activity_log_id', $entry->activity_log_id)->update(['description' => $entry->description]);
        // Bounded UI operation history cannot be the evidence source.
        for ($i = 0; $i < 55; $i++) {
            DB::table('university_email_operations')->insert(['operation_id' => (string) \Illuminate\Support\Str::uuid(),
                'university_email_id' => $op->university_email_id, 'kind' => 'suspend', 'status' => 'cancelled', 'generation' => 1,
                'draft_revision' => $op->draft_revision, 'email_address' => $op->email_address, 'quota_mb' => 50,
                'initiated_by_user_id' => 8, 'issued_by_user_id' => 8, 'reason' => 'Synthetic cancelled maintenance',
                'cancelled_at' => now(), 'cancelled_by_user_id' => 8,
                'created_at' => now()->addSeconds($i + 1), 'updated_at' => now()->addSeconds($i + 1)]);
        }
        $state = $this->getJson(self::API.'/provisioning')->assertOk()
            ->assertJsonPath('data.credential_state.status', 'lost_after_reconciliation')->json('data');
        $this->assertCount(50, $state['operations']);
        $this->assertFalse(collect($state['operations'])->contains('operation_id', $op->operation_id));
        $writes = $this->writes; $logs = DB::table('user_activity_logs')->count();
        DB::table('user_activity_logs')->where('activity_log_id', $entry->activity_log_id)->delete();
        $this->getJson(self::API.'/provisioning')->assertOk()->assertJsonPath('data.credential_state.status', 'none');
        $this->assertSame($writes, $this->writes); $this->assertSame($logs - 1, DB::table('user_activity_logs')->count());
    }
}
