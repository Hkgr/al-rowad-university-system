<?php

namespace Tests\Feature;

use App\Models\{StudentUniversityEmail, UniversityEmailOperation, User};
use App\Services\{UniversityEmailProvisioningService, UserIdentityService};
use App\Support\UniversityEmailAccess as Access;
use Illuminate\Support\Facades\{DB, Http};
use Laravel\Sanctum\Sanctum;

/** Real HTTP + middleware, isolated SQLite, only upstream Mailcow faked. Not lock/concurrency proof. */
class UniversityEmailLifecycleTest extends UniversityEmailPhase2Test
{
    private const API = '/api/v1/technical/university-email/students/1';
    private int $deletes = 0;
    private int $activeWrites = 0;
    private ?string $deleteFailure = null;
    private bool $cancelDuringPreflight = false;
    private bool $checkAfterDeletion = false;

    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_10_01_000003_add_university_email_account_management.php'))->up();
        (require database_path('migrations/2026_10_02_000000_add_university_email_deletion_lifecycle.php'))->up();
        $this->artisan('university-email:enable-permissions --phase3')->assertExitCode(0);
        DB::table('user_access_scopes')->where('user_id', 8)->delete();
        // Preserve the complete inherited failure matrix, extending only the new remote operation.
        $callbacks = (new \ReflectionProperty(\Illuminate\Http\Client\Factory::class, 'stubCallbacks'))->getValue(Http::getFacadeRoot());
        // Fakes are composed: the callable handles delete, forwarding other requests to the original fake.
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($callbacks) {
            $this->assertSame(0, DB::transactionLevel());
            if ($request->method() === 'GET' && str_contains($request->url(), '/get/mailbox/')) {
                if ($this->deleteFailure === 'before_write') throw new \Illuminate\Http\Client\ConnectionException('Synthetic timeout');
                if ($this->cancelDuringPreflight) {
                    $this->cancelDuringPreflight = false;
                    $op = UniversityEmailOperation::where('kind', 'delete')->where('active_slot', 1)->firstOrFail();
                    app(UniversityEmailProvisioningService::class)->cancel(User::findOrFail(8), 1, $op->operation_id, $op->generation);
                }
                if ($this->checkAfterDeletion && $this->deleteFailure === 'verification_timeout') throw new \Illuminate\Http\Client\ConnectionException('Synthetic read timeout');
            }
            if (str_contains($request->url(), '/delete/mailbox')) {
                $this->deletes++;
                $address = $request->data()[0];
                $op = UniversityEmailOperation::where('kind', 'delete')->where('active_slot', 1)->firstOrFail();
                try { app(UniversityEmailProvisioningService::class)->cancel(User::findOrFail($op->issued_by_user_id), 1, $op->operation_id, $op->generation); $this->fail('Started delete cannot be cancelled'); }
                catch (\App\Exceptions\UniversityEmailException $e) { $this->assertSame('university_email_operation_not_cancellable', $e->errorCode); }
                if ($this->deleteFailure !== 'still_present') unset($this->boxes[$address]);
                $this->checkAfterDeletion = true;
                if ($this->deleteFailure === 'lost_reply') throw new \Illuminate\Http\Client\ConnectionException('Synthetic lost reply');
                return Http::response([['type' => 'success', 'msg' => ['mailbox_removed', $address]]]);
            }
            if (str_contains($request->url(), '/edit/mailbox') && array_key_exists('active', $request->data()['attr'] ?? [])) {
                $this->activeWrites++;
                $address = $request->data()['items'][0];
                $this->boxes[$address]['active_int'] = (int) $request->data()['attr']['active'];
                return Http::response([['type' => 'success', 'msg' => ['mailbox_modified', $address]]]);
            }
            // Original fixture transport retains its zero-write guards and failure scenarios.
            return $callbacks->map(fn ($callback) => $callback($request, []))->filter()->first();
        });
    }

    private function createMailbox(): array
    {
        return $this->postJson(self::API.'/create', ['english_first_name' => 'Ahmad', 'confirmed' => true])->assertOk()->json('data');
    }
    private function deleteInput(): array
    {
        return ['revision' => StudentUniversityEmail::firstOrFail()->revision, 'student_number_confirmation' => 'R24011002', 'confirmed' => true, 'reason' => 'Synthetic deletion request'];
    }
    private function removeMailbox(): \Illuminate\Testing\TestResponse { return $this->postJson(self::API.'/delete-mailbox', $this->deleteInput()); }

    public function test_delivered_account_management_preserves_handover_and_has_no_orphan_operation(): void
    {
        $this->createMailbox();
        StudentUniversityEmail::firstOrFail()->update(['handover_status' => 'delivered']);
        foreach (['suspend' => false, 'activate' => true] as $kind => $active) {
            $this->postJson(self::API.'/account-action', ['kind' => $kind, 'revision' => 1,
                'reason' => 'Synthetic delivered management', 'confirmed' => true])->assertOk()
                ->assertJsonPath('data.handover_status', 'delivered')->assertJsonPath('data.remote_snapshot.active', $active);
            $this->assertSame(0, UniversityEmailOperation::where('active_slot', 1)->count());
            $this->assertDatabaseHas('university_email_operations', ['kind' => $kind, 'status' => 'confirmed']);
        }
        $this->assertSame(2, $this->activeWrites);
        $this->removeMailbox()->assertOk()->assertJsonPath('data.provisioning_status', 'deleted')
            ->assertJsonPath('data.handover_status', 'delivered');
        $this->assertSame(1, $this->deletes);
        $this->assertSame(0, UniversityEmailOperation::where('active_slot', 1)->count());
    }

    public function test_delivered_general_reset_and_receipt_preserve_handover_and_historical_issuer(): void
    {
        $created = $this->createMailbox();
        StudentUniversityEmail::firstOrFail()->update(['handover_status' => 'delivered']);
        $c = $created['credentials'];
        $this->postJson(self::API.'/provisioning/receipt', ['operation_id' => $c['operation_id'], 'generation' => $c['generation']])
            ->assertConflict()->assertJsonPath('error_code', 'university_email_credentials_unavailable');
        $reset = $this->postJson(self::API.'/reset-password-now', ['revision' => 1, 'reason' => 'Synthetic delivered reset', 'confirmed' => true])
            ->assertOk()->assertJsonPath('data.handover_status', 'delivered')->json('data.credentials');
        $this->assertSame(24, strlen($reset['password']));
        $this->assertSame(2, $this->writes); // Initial create + exactly one password write.
        $this->assertSame(0, UniversityEmailOperation::where('active_slot', 1)->count());
        $execute = array_diff_key($reset, ['kind' => true]);
        $execute['confirmed'] = true;
        $execute['credential_proof'] = str_repeat('0', 64); // Completed UUID is stale before any proof/remote action.
        $this->postJson(self::API.'/provisioning/execute', $execute)->assertConflict();
        $this->assertSame(2, $this->writes);
        Sanctum::actingAs(User::findOrFail(1));
        $receipt = $this->postJson(self::API.'/provisioning/receipt', ['operation_id' => $reset['operation_id'], 'generation' => $reset['generation']])
            ->assertOk()->assertJsonPath('data.receipt_purpose', 'password_reset')->json('data');
        $this->assertSame(app(UserIdentityService::class)->documentGenerator(User::findOrFail(8))['display_name'], $receipt['employee']);
        $this->assertArrayNotHasKey('password', $receipt);
        $this->assertDatabaseHas('student_university_emails', ['handover_status' => 'delivered', 'credential_operation_id' => $reset['operation_id']]);
    }

    public function test_initial_credentials_and_deterministic_rejections_do_not_leave_prepared_operations(): void
    {
        $this->putJson(self::API.'/draft', ['english_first_name' => 'Ahmad', 'revision' => 0])->assertOk();
        $email = StudentUniversityEmail::firstOrFail();
        $email->update(['handover_status' => 'delivered']);
        $service = app(UniversityEmailProvisioningService::class);
        try { $service->password(User::findOrFail(8), 1, 1, 'create'); $this->fail('Initial credentials require undelivered state'); }
        catch (\App\Exceptions\UniversityEmailException $e) { $this->assertSame('university_email_already_delivered', $e->errorCode); }
        $this->assertDatabaseCount('university_email_operations', 0);
        $email->update(['handover_status' => 'not_delivered']);
        config(['mailcow.password_length' => 23]);
        try { $service->password(User::findOrFail(8), 1, 1, 'create'); $this->fail('Invalid configuration must fail before preparation'); }
        catch (\App\Exceptions\UniversityEmailException $e) { $this->assertSame('university_email_configuration_invalid', $e->errorCode); }
        $this->assertDatabaseCount('university_email_operations', 0);
        config(['mailcow.password_length' => 24]);
        $created = $this->createMailbox();
        $this->assertSame('not_delivered', $created['handover_status']);
        $email->refresh()->update(['handover_status' => 'delivered']);
        try { $service->password(User::findOrFail(8), 1, $email->revision, 'reset'); $this->fail('Legacy reset remains pre-handover'); }
        catch (\App\Exceptions\UniversityEmailException $e) { $this->assertSame('university_email_already_delivered', $e->errorCode); }
        $this->postJson(self::API.'/delete-mailbox', array_replace($this->deleteInput(), ['student_number_confirmation' => 'wrong']))->assertUnprocessable();
        $this->assertDatabaseCount('university_email_operations', 1);
        $this->assertSame(0, UniversityEmailOperation::where('active_slot', 1)->count());
    }

    public function test_central_targeting_and_search_never_grant_generic_student_access(): void
    {
        foreach (['أحمد', 'اختبار', 'أحمد اختبار', 'اختبار أحمد', 'R24011002'] as $q) {
            $this->getJson('/api/v1/technical/university-email/students?q='.urlencode($q))->assertOk()->assertJsonPath('meta.total', 1);
        }
        DB::table('students')->where('student_id', 1)->update(['father_name' => 'والداصطناعي']);
        $this->getJson('/api/v1/technical/university-email/students?q='.urlencode('والداصطناعي'))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/technical/university-email/students')->assertOk()->assertJsonPath('meta.total', \App\Models\Student::count());
        $this->getJson('/api/v1/technical/university-email/students?per_page=2&page=2')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/students')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_delete_preserves_history_and_recreate_reuses_root_and_rejects_old_cycle(): void
    {
        $created = $this->createMailbox(); $c = $created['credentials'];
        $this->postJson(self::API.'/provisioning/receipt', ['operation_id' => $c['operation_id'], 'generation' => $c['generation']])->assertOk();
        StudentUniversityEmail::firstOrFail()->update(['handover_status' => 'delivered']);
        $rootId = StudentUniversityEmail::firstOrFail()->getKey();
        $this->removeMailbox()->assertOk()->assertJsonPath('data.provisioning_status', 'deleted')->assertJsonPath('data.creation_operation_id', null)->assertJsonPath('data.credential_operation_id', null);
        $this->assertSame(1, $this->deletes);
        $this->assertDatabaseCount('student_university_emails', 1); $this->assertDatabaseCount('university_email_receipts', 1);
        $delete = UniversityEmailOperation::where('kind', 'delete')->firstOrFail();
        $before = $delete->getAttributes();
        $deletedRoot = StudentUniversityEmail::firstOrFail();
        $this->assertSame('deleted', $deletedRoot->provisioning_status);
        $this->assertSame('delivered', $deletedRoot->handover_status);
        $this->assertSame(['exists' => false], $deletedRoot->remote_snapshot);
        $this->assertNotNull($deletedRoot->deleted_at);
        $this->assertNull($deletedRoot->creation_operation_id);
        $this->assertNull($deletedRoot->credential_operation_id);
        $response = $this->postJson(self::API.'/recreate', ['english_first_name' => 'Corrected', 'revision' => StudentUniversityEmail::first()->revision, 'confirmed' => true]);
        $this->assertSame(200, $response->status(), 'Recreation response: '.($response->json('error_code') ?? 'confirmed'));
        $next = $response->json('data');
        $this->assertSame($rootId, StudentUniversityEmail::first()->getKey());
        $this->assertNotSame($c['operation_id'], $next['creation_operation_id']);
        $this->assertGreaterThan($deletedRoot->lifecycle_revision, StudentUniversityEmail::first()->lifecycle_revision);
        $this->assertSame($next['creation_operation_id'], $next['credential_operation_id'], 'Recreation must publish only its own confirmed credential reference');
        $this->assertDatabaseCount('student_university_emails', 1); $this->assertDatabaseCount('university_email_operations', 3);
        $this->assertDatabaseCount('university_email_receipts', 1); $this->assertSame($before, $delete->fresh()->getAttributes());
        $this->postJson(self::API.'/provisioning/reconcile', ['operation_id' => $delete->operation_id])->assertConflict()->assertJsonPath('error_code', 'university_email_operation_stale');
        $this->postJson(self::API.'/provisioning/execute-account', ['operation_id' => $delete->operation_id, 'generation' => $delete->generation, 'confirmed' => true])->assertConflict();
        $this->postJson(self::API.'/provisioning/execute', ['operation_id' => $c['operation_id'], 'generation' => $c['generation'],
            'password' => $c['password'], 'credential_proof' => hash_hmac('sha256', $c['operation_id'].'|'.$c['generation'].'|8|'.$c['password'], (string) config('app.key')),
            'confirmed' => true])->assertConflict()->assertJsonPath('error_code', 'university_email_operation_stale');
        $this->postJson(self::API.'/provisioning/receipt', ['operation_id' => $c['operation_id'], 'generation' => $c['generation']])->assertConflict();
        $this->assertSame(2, $this->writes); $this->assertSame(1, $this->deletes);
        $this->assertDatabaseHas('student_university_emails', ['handover_status' => 'not_delivered']);
    }

    public function test_local_status_filters_match_total_and_do_not_call_mailcow(): void
    {
        $this->createMailbox();
        $requests = count(Http::recorded());
        $url = '/api/v1/technical/university-email/students';
        $total = $this->getJson($url)->assertOk()->json('meta.total');
        $this->getJson($url.'?status=active')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.email_preparation.account_status', 'active');
        $this->getJson($url.'?status=not_created')->assertOk()->assertJsonPath('meta.total', $total - 1);
        $this->assertSame($requests, count(Http::recorded()));
        $this->boxes[StudentUniversityEmail::first()->email_address]['active_int'] = 0;
        $this->postJson(self::API.'/provisioning/refresh-account')->assertOk();
        $this->getJson($url.'?status=suspended')->assertOk()->assertJsonPath('meta.total', 1);
        $this->removeMailbox()->assertOk();
        $this->getJson($url.'?status=deleted')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.email_preparation.account_status', 'deleted');
        $this->getJson($url.'?status=not_created')->assertOk()->assertJsonPath('meta.total', $total - 1);
    }

    public function test_new_schema_unavailable_preserves_reads_but_blocks_deletion_and_recreation(): void
    {
        $this->createMailbox();
        \Illuminate\Support\Facades\Schema::table('student_university_emails', fn ($t) => $t->dropColumn('lifecycle_revision'));
        $requests = count(Http::recorded());
        $this->getJson(self::API.'/provisioning')->assertOk()->assertJsonPath('data.deletion_schema_ready', false);
        $this->removeMailbox()->assertStatus(503)->assertJsonPath('error_code', 'university_email_deletion_schema_not_ready');
        $this->postJson(self::API.'/recreate', ['english_first_name' => 'Other', 'revision' => 1, 'confirmed' => true])->assertStatus(503);
        $this->assertSame($requests, count(Http::recorded()));
        $this->assertDatabaseHas('student_university_emails', ['provisioning_status' => 'created']);
    }

    public function test_one_step_legacy_link_preserves_explicit_preview_attestation_and_permission(): void
    {
        $this->putJson(self::API.'/draft', ['english_first_name' => 'Ahmad', 'revision' => 0])->assertOk();
        $address = 'previous.synthetic@alrowaduni.edu.sy';
        $this->boxes[$address] = ['username' => $address, 'domain' => 'alrowaduni.edu.sy', 'quota' => 50 * 1048576,
            'active_int' => 1, 'attributes' => ['force_pw_update' => 1], 'tags' => ['existing-synthetic-tag']];
        $preview = $this->postJson(self::API.'/provisioning/preview-link', ['email_address' => $address])->assertOk()->json('data');
        $input = ['kind' => 'link', 'revision' => 1, 'reason' => 'Synthetic verified ownership', 'confirmed' => true,
            'email_address' => $address, 'preview_proof' => $preview['preview_proof'], 'ownership_confirmed' => false];
        $this->postJson(self::API.'/account-action', $input)->assertUnprocessable();
        $this->assertSame(0, $this->writes);
        $input['ownership_confirmed'] = true;
        StudentUniversityEmail::firstOrFail()->update(['handover_status' => 'delivered']);
        $this->postJson(self::API.'/account-action', $input)->assertConflict()->assertJsonPath('error_code', 'university_email_already_delivered');
        $this->assertDatabaseCount('university_email_operations', 0);
        StudentUniversityEmail::firstOrFail()->update(['handover_status' => 'not_delivered']);
        $this->postJson(self::API.'/account-action', $input)->assertOk()->assertJsonPath('data.linkage_origin', 'linked');
        $this->assertSame(1, $this->writes);
        $this->assertDatabaseHas('student_university_emails', ['email_address' => $address, 'creation_operation_id' => UniversityEmailOperation::first()->operation_id, 'credential_operation_id' => null]);
    }

    public function test_read_verification_is_required_and_uncertain_delete_never_reposts(): void
    {
        $this->createMailbox(); $this->deleteFailure = 'still_present';
        $this->removeMailbox()->assertConflict()->assertJsonPath('error_code', 'university_email_remote_verification_failed');
        $op = UniversityEmailOperation::where('kind', 'delete')->firstOrFail();
        $this->assertSame('uncertain', $op->status);
        $this->removeMailbox()->assertConflict();
        $this->travel(2)->minutes();
        $this->postJson(self::API.'/provisioning/reconcile', ['operation_id' => $op->operation_id])->assertConflict()->assertJsonPath('error_code', 'university_email_manual_review_required');
        $this->assertSame(1, $this->deletes); $this->assertSame('created', StudentUniversityEmail::first()->provisioning_status);
    }

    public function test_lost_delete_reply_is_confirmed_by_read_only_missing_mailbox(): void
    {
        $this->createMailbox(); $this->deleteFailure = 'lost_reply';
        StudentUniversityEmail::firstOrFail()->update(['handover_status' => 'delivered']);
        $this->removeMailbox()->assertConflict(); $op = UniversityEmailOperation::where('kind', 'delete')->firstOrFail();
        $this->assertSame('uncertain', $op->status); $this->travel(2)->minutes();
        $this->postJson(self::API.'/provisioning/reconcile', ['operation_id' => $op->operation_id])->assertOk()->assertJsonPath('data.provisioning_status', 'deleted');
        $this->assertSame(1, $this->deletes);
        $this->assertDatabaseHas('student_university_emails', ['handover_status' => 'delivered', 'provisioning_status' => 'deleted']);
        $this->assertSame(0, UniversityEmailOperation::where('active_slot', 1)->count());
    }

    public function test_prewrite_timeout_and_cancelled_worker_send_no_delete(): void
    {
        $this->createMailbox(); $this->deleteFailure = 'before_write';
        $this->removeMailbox()->assertConflict();
        $op = UniversityEmailOperation::where('kind', 'delete')->firstOrFail();
        $this->assertNull($op->write_started_at); $this->assertSame(0, $this->deletes);
        $this->postJson(self::API.'/provisioning/cancel', ['operation_id' => $op->operation_id, 'generation' => $op->generation, 'confirmed' => true])->assertOk();
        $this->deleteFailure = null; $this->cancelDuringPreflight = true;
        $this->removeMailbox()->assertConflict()->assertJsonPath('error_code', 'university_email_operation_stale');
        $this->assertSame(0, $this->deletes); $this->assertSame('created', StudentUniversityEmail::first()->provisioning_status);
    }

    public function test_delete_requires_exact_confirmation_dedicated_permission_and_ownership(): void
    {
        $this->createMailbox();
        $this->postJson(self::API.'/delete-mailbox', array_replace($this->deleteInput(), ['student_number_confirmation' => 'other']))->assertUnprocessable();
        DB::table('permissions')->where('permission_code', Access::DELETE)->update(['is_active' => false]); Sanctum::actingAs(User::findOrFail(8));
        $this->removeMailbox()->assertForbidden();
        Sanctum::actingAs(User::findOrFail(1)); $this->removeMailbox()->assertOk(); // Super-admin, no technical role/scope needed.
        $this->assertSame(1, $this->deletes);
    }

    public function test_delete_unowned_mailbox_is_denied_without_remote_write(): void
    {
        $this->createMailbox(); $address = StudentUniversityEmail::first()->email_address; $this->boxes[$address]['tags'] = [];
        $this->removeMailbox()->assertConflict()->assertJsonPath('error_code', 'university_email_not_owned');
        $this->assertSame(0, $this->deletes);
    }

    public function test_inactive_owned_mailbox_can_be_deleted(): void
    {
        $this->createMailbox(); $address = StudentUniversityEmail::first()->email_address; $this->boxes[$address]['active_int'] = 0;
        $this->removeMailbox()->assertOk()->assertJsonPath('data.provisioning_status', 'deleted'); $this->assertSame(1, $this->deletes);
    }

    public function test_receipt_actor_is_the_credential_issuer_even_for_another_downloader_and_reset(): void
    {
        $created = $this->createMailbox();
        Sanctum::actingAs(User::findOrFail(1));
        $c = $created['credentials'];
        $receipt = $this->postJson(self::API.'/provisioning/receipt', ['operation_id' => $c['operation_id'], 'generation' => $c['generation']])->assertOk()->json('data');
        $this->assertSame(app(UserIdentityService::class)->documentGenerator(User::findOrFail(8))['display_name'], $receipt['employee']);
        $this->assertArrayNotHasKey('password', $receipt); $this->assertArrayHasKey('program', $receipt['student']);
        config(['mailcow.password_length' => 64]);
        $reset = $this->postJson(self::API.'/reset-password-now', ['revision' => 1, 'reason' => 'Synthetic reset', 'confirmed' => true])->assertOk()->json('data');
        $this->assertSame(64, strlen($reset['credentials']['password']));
        Sanctum::actingAs(User::findOrFail(8));
        $r = $reset['credentials'];
        $receipt = $this->postJson(self::API.'/provisioning/receipt', ['operation_id' => $r['operation_id'], 'generation' => $r['generation']])->assertOk()->json('data');
        $this->assertSame('password_reset', $receipt['receipt_purpose']);
        $this->assertSame(app(UserIdentityService::class)->documentGenerator(User::findOrFail(1))['display_name'], $receipt['employee']);
        $this->assertDatabaseHas('student_university_emails', ['handover_status' => 'not_delivered']);
    }
}
