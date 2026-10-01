<?php

namespace Tests\Feature;

use App\Models\{User, StudentUniversityEmail};
use App\Support\UniversityEmailAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Http, Schema};
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MinistryReadFixture;
use Tests\TestCase;

class UniversityEmailPhase1Test extends TestCase
{
    use MinistryReadFixture;
    private const ROLE_MINISTRY = 2;
    private const ROOT = '/api/v1/technical/university-email';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
        Schema::create('system_modules', function (Blueprint $t): void { $t->increments('module_id'); $t->string('module_code'); $t->string('module_name'); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('user_activity_logs', function (Blueprint $t): void { $t->bigIncrements('activity_log_id'); $t->integer('user_id'); $t->string('module_code'); $t->string('action_code'); $t->text('description'); $t->string('ip_address')->nullable(); $t->timestamp('created_at')->nullable(); });
        DB::table('system_modules')->insert(['module_id' => 1, 'module_code' => 'users_permissions', 'module_name' => 'Synthetic permissions', 'is_active' => true]);
        DB::table('roles')->insert(['role_id' => 10, 'role_code' => 'technical_team', 'role_name' => 'Technical', 'is_active' => true]);
        DB::table('user_roles')->insert(['user_id' => 8, 'role_id' => 10, 'is_active' => true]);
        $portal = DB::table('permissions')->insertGetId(['permission_code' => 'technical_portal.access', 'permission_name' => 'Portal', 'module_id' => 1, 'is_active' => true], 'permission_id');
        DB::table('role_permissions')->insert(['role_id' => 10, 'permission_id' => $portal]);
        DB::table('user_access_scopes')->insert(['user_id' => 8, 'scope_type' => 'university', 'scope_id' => 91, 'is_active' => true]);
        $this->artisan('university-email:enable-permissions')->assertExitCode(0);
        (require database_path('migrations/2026_10_01_000000_create_student_university_emails.php'))->up();
        DB::table('students')->where('student_id', 1)->update(['student_number' => 'R24011002', 'first_name' => 'أحمد', 'last_name' => 'اختبار', 'email' => 'personal@example.invalid']);
        config(['mailcow.student_domain' => 'alrowaduni.edu.sy', 'mailcow.student_quota_mb' => 50, 'mailcow.base_url' => 'https://mail.alrowaduni.edu.sy', 'mailcow.api_key' => 'synthetic-test-secret']);
        Http::preventStrayRequests();
        Sanctum::actingAs(User::findOrFail(8));
    }

    private function save(array $input = []): \Illuminate\Testing\TestResponse
    {
        return $this->putJson(self::ROOT.'/students/1/draft', $input ?: ['english_first_name' => ' Ahmad ', 'revision' => 0]);
    }

    public function test_empty_search_through_real_normalizing_http_middleware(): void
    {
        $total = $this->getJson(self::ROOT.'/students')->assertOk()->json('meta.total');
        foreach (['?q=', '?q=%20%20%20'] as $query) {
            $this->getJson(self::ROOT.'/students'.$query)->assertOk()->assertJsonPath('meta.total', $total);
        }
        $this->getJson(self::ROOT.'/students?q='.urlencode('أحمد'))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson(self::ROOT.'/students?q=R24011002')->assertOk()->assertJsonPath('meta.total', 1);
        foreach (['?q[]=invalid', '?q='.str_repeat('a', 121), '?page=invalid'] as $query) {
            $this->getJson(self::ROOT.'/students'.$query)->assertUnprocessable();
        }
        $this->assertDatabaseCount('student_university_emails', 0);
        Http::assertNothingSent();
    }

    public function test_whitespace_search_through_real_normalizing_http_middleware(): void
    {
        $this->getJson(self::ROOT.'/students?q=%20%20%20')->assertOk();
    }

    public function test_search_has_safe_local_preparation_and_separate_handover_summaries(): void
    {
        $url = self::ROOT.'/students?q=R24011002';
        $this->getJson($url)->assertOk()->assertJsonPath('email_schema_ready', true)
            ->assertJsonPath('data.0.email_preparation', ['available' => true, 'email_address' => null, 'provisioning_status' => null, 'handover_status' => null]);
        $saved = $this->save()->assertOk();
        $summary = $saved->json('data.student.email_preparation');
        $this->assertSame(['available', 'email_address', 'provisioning_status', 'handover_status'], array_keys($summary));
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.email_preparation', $summary);
        $this->assertSame('draft', $summary['provisioning_status']);
        $this->assertSame('not_delivered', $summary['handover_status']);
        DB::table('student_university_emails')->where('student_id', 1)->update(['provisioning_status' => 'created', 'handover_status' => 'delivered']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.email_preparation.provisioning_status', 'created')
            ->assertJsonPath('data.0.email_preparation.handover_status', 'delivered');
        $this->assertDatabaseCount('user_activity_logs', 1); // search never writes or calls Mailcow
        Http::assertNothingSent();
    }

    public function test_export_isolated_synthetic_browser_fixture_when_explicitly_requested(): void
    {
        // Opt-in fixture export for real React -> Laravel checks, never production data.
        $this->getJson(self::ROOT.'/students')->assertOk();
        if (! ($directory = getenv('UNIVERSITY_EMAIL_BROWSER_DIR'))) return;
        $directory = realpath($directory);
        $this->assertNotFalse($directory);
        $this->assertStringStartsWith(strtolower(realpath(sys_get_temp_dir())).DIRECTORY_SEPARATOR, strtolower($directory).DIRECTORY_SEPARATOR);
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $student = (array) DB::table('students')->where('student_id', 1)->first();
        for ($id = 100; $id < 130; $id++) {
            DB::table('students')->insert(array_replace($student, ['student_id' => $id, 'student_number' => 'RTEST'.$id, 'first_name' => 'طالب', 'last_name' => 'تجريبي '.$id]));
        }
        Schema::create('personal_access_tokens', function (Blueprint $t): void {
            $t->id(); $t->string('tokenable_type'); $t->unsignedBigInteger('tokenable_id');
            $t->string('name'); $t->string('token', 64)->unique(); $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable(); $t->timestamp('expires_at')->nullable(); $t->timestamps();
        });
        $actors = [];
        foreach (['technical' => 8, 'unauthorized' => 1] as $name => $id) {
            $user = User::findOrFail($id);
            $actors[$name] = ['identity' => app(\App\Services\UserIdentityService::class)->payload($user),
                'token' => $user->createToken('isolated-email-browser')->plainTextToken];
        }
        file_put_contents($directory.'/identities.json', json_encode($actors, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        DB::statement('VACUUM INTO ?', [$directory.'/email.sqlite']);
    }

    public function test_search_and_read_are_scoped_paginated_and_write_nothing_without_login_account(): void
    {
        $this->getJson(self::ROOT.'/students?q='.urlencode('أحمد اختبار'))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.student_number', 'R24011002');
        $this->getJson(self::ROOT.'/students?q=R24011002')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson(self::ROOT.'/students/1')->assertOk()->assertJsonPath('data.draft', null)->assertJsonMissing(['email' => 'personal@example.invalid']);
        $this->getJson(self::ROOT.'/students?per_page=101')->assertUnprocessable();
        $this->assertDatabaseCount('student_university_emails', 0);
        $this->assertDatabaseCount('user_activity_logs', 0);
        Http::assertNothingSent();
        DB::table('user_access_scopes')->where('user_id', 8)->delete();
        $this->getJson(self::ROOT.'/students')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::ROOT.'/students/1')->assertForbidden();
        $this->save()->assertForbidden();
    }

    public function test_local_draft_normalization_revision_audit_noop_aba_and_independent_identity(): void
    {
        $users = DB::table('users')->get()->toJson();
        config(['mailcow.api_key' => '']);
        $this->save()->assertOk()->assertJsonPath('data.draft.email_address', 'ahmad.r24011002@alrowaduni.edu.sy')
            ->assertJsonPath('data.draft.quota_mb', 50)->assertJsonPath('data.draft.provisioning_status', 'draft')->assertJsonPath('data.draft.handover_status', 'not_delivered')->assertJsonPath('data.draft.revision', 1);
        $this->getJson(self::ROOT.'/students/1')->assertOk()->assertJsonPath('data.draft.english_first_name', 'ahmad');
        $this->save(['english_first_name' => 'AHMAD', 'revision' => 1])->assertOk()->assertJsonPath('data.draft.revision', 1);
        $this->assertDatabaseCount('user_activity_logs', 1);
        $this->save(['english_first_name' => 'omar', 'revision' => 1])->assertOk()->assertJsonPath('data.draft.revision', 2);
        $this->save(['english_first_name' => 'ahmad', 'revision' => 2])->assertOk()->assertJsonPath('data.draft.revision', 3);
        $this->save(['english_first_name' => 'ali', 'revision' => 1])->assertConflict()->assertJsonPath('error_code', 'university_email_stale');
        $this->save()->assertConflict(); // competing first create cannot duplicate
        $this->assertDatabaseCount('student_university_emails', 1);
        $this->assertDatabaseCount('user_activity_logs', 3);
        $audit = DB::table('user_activity_logs')->first();
        $this->assertSame(8, $audit->user_id);
        $this->assertEqualsCanonicalizing(['student_id', 'record_id', 'revision'], array_keys(json_decode($audit->description, true)));
        $this->assertDatabaseHas('students', ['student_id' => 1, 'email' => 'personal@example.invalid']);
        $this->assertSame($users, DB::table('users')->get()->toJson());
        DB::table('students')->where('student_id', 1)->update(['student_number' => 'R24011099']);
        $this->getJson(self::ROOT.'/students/1')->assertOk()->assertJsonPath('data.draft.email_address', 'ahmad.r24011002@alrowaduni.edu.sy');
        Http::assertNothingSent();
    }

    public function test_invalid_names_payload_tampering_and_length_reject_without_writes(): void
    {
        foreach (['عبد', 'ali2', 'ali-omar', 'ali omar', 'é', str_repeat('a', 60)] as $name) {
            $this->save(['english_first_name' => $name, 'revision' => 0])->assertUnprocessable();
        }
        foreach (['student_number', 'domain', 'quota_mb', 'provisioning_status', 'email_address'] as $key) {
            $this->save(['english_first_name' => 'ahmad', 'revision' => 0, $key => 'tampered'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('student_university_emails', 0);
        $this->assertDatabaseCount('user_activity_logs', 0);
        $this->save(['english_first_name' => "\u{00a0}Abdulrahman\u{00a0}", 'revision' => 0])->assertOk()->assertJsonPath('data.draft.english_first_name', 'abdulrahman');
    }

    public function test_permission_role_and_active_account_enforced_for_every_endpoint(): void
    {
        foreach ([1, 2, 3, 4, 6, 7] as $id) {
            Sanctum::actingAs(User::findOrFail($id));
            $this->getJson(self::ROOT.'/students')->assertForbidden();
            $this->getJson(self::ROOT.'/students/1')->assertForbidden();
            $this->save()->assertForbidden();
            $this->getJson(self::ROOT.'/connection')->assertForbidden();
        }
        Sanctum::actingAs(User::findOrFail(8));
        $this->revoke(UniversityEmailAccess::MANAGE);
        $this->getJson(self::ROOT.'/students/1')->assertOk();
        $this->save()->assertForbidden();
        $this->revoke(UniversityEmailAccess::CHECK);
        $this->getJson(self::ROOT.'/connection')->assertForbidden();
        $this->revoke(UniversityEmailAccess::VIEW);
        $this->getJson(self::ROOT.'/students')->assertForbidden();
        $this->getJson(self::ROOT.'/students/1')->assertForbidden();
        Http::assertNothingSent();
    }

    private function revoke(string $code): void
    {
        DB::table('role_permissions')->where('role_id', 10)->where('permission_id', DB::table('permissions')->where('permission_code', $code)->value('permission_id'))->delete();
    }

    public function test_targeted_permission_provisioning_idempotence_and_no_admin_grant(): void
    {
        $before = DB::table('role_permissions')->count();
        $this->artisan('university-email:enable-permissions')->assertExitCode(0);
        $this->assertSame($before, DB::table('role_permissions')->count());
        $this->assertSame(0, DB::table('role_permissions')->where('role_id', 1)->whereIn('permission_id', DB::table('permissions')->whereIn('permission_code', array_keys(UniversityEmailAccess::PERMISSIONS))->pluck('permission_id'))->count());
        DB::table('permissions')->where('permission_code', UniversityEmailAccess::MANAGE)->update(['is_active' => false]);
        $this->artisan('university-email:enable-permissions')->assertExitCode(1);
    }

    public function test_inactive_and_unauthenticated_technical_accounts_are_rejected(): void
    {
        DB::table('users')->where('user_id', 8)->update(['account_status_id' => 2]);
        Sanctum::actingAs(User::findOrFail(8));
        $this->getJson(self::ROOT.'/students')->assertForbidden();
        $this->save()->assertForbidden();
        $this->getJson(self::ROOT.'/connection')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson(self::ROOT.'/students')->assertUnauthorized();
        $this->save()->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_search_query_count_is_bounded_and_actual_college_scope_is_preserved(): void
    {
        DB::table('user_access_scopes')->where('user_id', 8)->update(['scope_type' => 'college', 'scope_id' => 1]);
        $this->save()->assertOk();
        DB::enableQueryLog();
        $first = $this->getJson(self::ROOT.'/students?per_page=100')->assertOk();
        $queries = count(DB::getQueryLog());
        $student = (array) DB::table('students')->where('student_id', 1)->first();
        $draft = StudentUniversityEmail::first()->getAttributes();
        unset($draft['university_email_id']);
        for ($id = 100; $id < 130; $id++) {
            DB::table('students')->insert(array_replace($student, ['student_id' => $id, 'student_number' => 'RTEST'.$id]));
            DB::table('student_university_emails')->insert(array_replace($draft, ['student_id' => $id, 'email_address' => 'test'.$id.'@alrowaduni.edu.sy']));
        }
        DB::flushQueryLog();
        $many = $this->getJson(self::ROOT.'/students?per_page=100')->assertOk();
        $this->assertSame($first->json('meta.total') + 30, $many->json('meta.total'));
        $this->assertLessThanOrEqual($queries + 1, count(DB::getQueryLog()));
        $emailQueries = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'select') && str_contains($q['query'], 'from "student_university_emails"'));
        $this->assertCount(1, $emailQueries);
        $this->assertSame(31, collect($many->json('data'))->where('email_preparation.provisioning_status', 'draft')->count());
        DB::disableQueryLog();
        $outside = DB::table('students')->whereIn('academic_program_id', DB::table('academic_programs')->whereIn('department_id', DB::table('departments')->where('college_id', 2)->pluck('department_id'))->pluck('academic_program_id'))->value('student_id');
        $this->assertNotNull($outside);
        $this->getJson(self::ROOT.'/students/'.$outside)->assertForbidden();
        $this->putJson(self::ROOT.'/students/'.$outside.'/draft', ['revision' => 0, 'english_first_name' => 'ali'])->assertForbidden();
    }

    public function test_created_or_delivered_records_cannot_be_edited_as_drafts(): void
    {
        $this->save()->assertOk();
        DB::table('student_university_emails')->where('student_id', 1)->update(['provisioning_status' => 'created']);
        $this->save(['english_first_name' => 'ali', 'revision' => 1])->assertConflict()->assertJsonPath('error_code', 'university_email_not_draft');
        $this->assertDatabaseHas('student_university_emails', ['english_first_name' => 'ahmad', 'revision' => 1]);
        $this->assertDatabaseCount('user_activity_logs', 1);
    }

    public function test_unique_constraints_and_rollback_preserve_saved_records_and_audit_atomicity(): void
    {
        $this->save()->assertOk();
        $migration = require database_path('migrations/2026_10_01_000000_create_student_university_emails.php');
        try { $migration->down(); $this->fail('Populated rollback must be blocked'); } catch (\RuntimeException $e) { $this->assertStringContainsString('rollback refused', $e->getMessage()); }
        $attributes = StudentUniversityEmail::first()->getAttributes();
        unset($attributes['university_email_id']);
        foreach ([['student_id' => 1, 'email_address' => 'unique@example.invalid'], ['student_id' => 2, 'email_address' => $attributes['email_address']]] as $conflict) {
            try { DB::table('student_university_emails')->insert(array_replace($attributes, $conflict)); $this->fail('Unique constraint missing'); }
            catch (\Illuminate\Database\UniqueConstraintViolationException) {}
        }
        DB::statement("CREATE TRIGGER fail_email_audit BEFORE INSERT ON user_activity_logs BEGIN SELECT RAISE(ABORT, 'synthetic_audit_failure'); END");
        $this->save(['english_first_name' => 'ali', 'revision' => 1])->assertStatus(500);
        $this->assertDatabaseHas('student_university_emails', ['revision' => 1, 'english_first_name' => 'ahmad']);
        $this->assertDatabaseCount('user_activity_logs', 1);
    }

    public function test_mailcow_read_only_safe_projection_transport_and_cli(): void
    {
        Http::fake(function ($request, array $options) {
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($options['verify']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(15, $options['timeout']);
            return Http::response([
            'domain_name' => 'alrowaduni.edu.sy', 'active_int' => 1, 'mboxes_in_domain' => 103, 'max_num_mboxes_for_domain' => 200,
            'secret' => 'synthetic-test-secret', 'irrelevant' => 'private']);
        });
        $response = $this->getJson(self::ROOT.'/connection')->assertOk()->assertJsonPath('data.remaining_mailboxes', 97);
        $this->assertStringNotContainsString('synthetic-test-secret', $response->getContent());
        $this->assertEqualsCanonicalizing(['domain', 'active', 'mailbox_count', 'mailbox_limit', 'remaining_mailboxes', 'checked_at'], array_keys($response->json('data')));
        $this->artisan('university-email:check')->expectsOutputToContain('alrowaduni.edu.sy')->assertExitCode(0);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->hasHeader('X-API-Key', 'synthetic-test-secret'));
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
        $options = Http::recorded()[0][0]->toPsrRequest();
        $this->assertSame('https://mail.alrowaduni.edu.sy/api/v1/get/domain/alrowaduni.edu.sy', (string) $options->getUri());
        $this->assertDatabaseCount('user_activity_logs', 0);
    }

    public function test_mailcow_failure_classifications_and_secret_suppression(): void
    {
        foreach ([[401, [], 'mailcow_authentication_failed'], [403, [], 'mailcow_authentication_failed'],
            [200, [['type' => 'danger', 'msg' => 'synthetic-test-secret']], 'mailcow_invalid_response'],
            [200, 'not JSON synthetic-test-secret', 'mailcow_invalid_response'], [302, [], 'mailcow_invalid_response'],
            [200, [], 'mailcow_domain_missing'], [404, [], 'mailcow_domain_missing'],
            [200, ['domain_name' => 'alrowaduni.edu.sy'], 'mailcow_invalid_response']] as [$status, $body, $code]) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response($body, $status)]);
            $r = $this->getJson(self::ROOT.'/connection')->assertStatus(502)->assertJsonPath('error_code', $code);
            $this->assertStringNotContainsString('synthetic-test-secret', $r->getContent());
        }
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('synthetic-test-secret'));
        $this->getJson(self::ROOT.'/connection')->assertStatus(503)->assertJsonPath('error_code', 'mailcow_connection_failed');
        config(['mailcow.api_key' => '']);
        $this->getJson(self::ROOT.'/connection')->assertStatus(503)->assertJsonPath('error_code', 'mailcow_configuration_missing');
        $this->artisan('university-email:check')->expectsOutputToContain('mailcow_configuration_missing')->assertExitCode(1);
        $this->assertDatabaseCount('student_university_emails', 0);
        $this->assertDatabaseCount('user_activity_logs', 0);
    }

    public function test_missing_local_schema_is_controlled_and_untrusted_health_urls_are_rejected(): void
    {
        $this->getJson(self::ROOT.'/connection?base_url=https://example.invalid')->assertUnprocessable();
        foreach (['http://mail.alrowaduni.edu.sy', 'https://user:synthetic-test-secret@mail.alrowaduni.edu.sy',
            'https://mail.alrowaduni.edu.sy/other', 'https://mail.alrowaduni.edu.sy?target=other'] as $url) {
            config(['mailcow.base_url' => $url]);
            $r = $this->getJson(self::ROOT.'/connection')->assertStatus(503)->assertJsonPath('error_code', 'mailcow_configuration_missing');
            $this->assertStringNotContainsString('synthetic-test-secret', $r->getContent());
        }
        Schema::drop('student_university_emails'); // Isolated testing SQLite only.
        $this->getJson(self::ROOT.'/students?q=R24011002')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('email_schema_ready', false)->assertJsonPath('data.0.email_preparation.available', false)
            ->assertJsonPath('data.0.email_preparation.provisioning_status', null);
        $this->getJson(self::ROOT.'/students/1')->assertStatus(503)->assertJsonPath('error_code', 'university_email_schema_not_ready');
        $this->save()->assertStatus(503)->assertJsonPath('error_code', 'university_email_schema_not_ready');
        Http::assertNothingSent();
    }
}
