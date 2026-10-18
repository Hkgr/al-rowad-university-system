<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\HrOffice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AdministrativeGovernanceSchema;
use Tests\TestCase;

final class HrOfficeTest extends TestCase
{
    use AdministrativeGovernanceSchema;

    private const API = '/api/v1/vice-presidency/administrative/hr';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAdministrativeGovernanceSchema();
        $this->seedAdministrativeGovernanceFixture();
        (require database_path('migrations/2026_10_08_000000_create_owner_payroll_tables.php'))->up();
        (require database_path('migrations/2026_10_09_100000_create_administrative_hr_office.php'))->up();
        $this->travelTo(now()->setDate(2026, 10, 9));
        $this->login(1);
    }

    private function login(int $id): void
    {
        Sanctum::actingAs(User::findOrFail($id), ['*']);
    }

    private function need(): array
    {
        return $this->postJson(self::API.'/needs', ['title' => 'احتياج اصطناعي', 'body' => 'educational', 'college_id' => 1, 'status' => 'open', 'items' => [
            ['job_title' => 'مدرس برمجة', 'quantity' => 2, 'education' => 'ماجستير', 'skills' => 'برمجة', 'is_active' => true],
            ['job_title' => 'مدرس رياضيات', 'quantity' => 1, 'education' => 'دكتوراه', 'skills' => 'رياضيات', 'is_active' => true],
        ]])->assertCreated()->json('data');
    }

    private function candidate(): array
    {
        $need = $this->need();
        $item = DB::table('hr_staffing_need_items')->where('need_id', $need['id'])->orderBy('id')->first();

        return $this->postJson(self::API.'/candidates', ['need_item_id' => $item->id, 'first_name' => 'مرشح', 'last_name' => 'اصطناعي', 'email' => 'synthetic@example.test'])->assertCreated()->json('data');
    }

    private function proposal(array $extra = []): array
    {
        return array_replace(['body' => 'educational', 'college_id' => 1, 'relationship_type' => 'temporary_contract', 'work_mode' => 'part', 'starts_on' => '2026-10-01', 'ends_on' => '2026-12-31', 'job_title' => 'مدرس', 'employee_number' => 'HR-TEST-NEW', 'employee_type_id' => 1], $extra);
    }

    private function draft(int $candidate): array
    {
        return $this->postJson(self::API.'/requests', ['candidate_id' => $candidate, 'kind' => 'accept', 'reason' => 'قبول مقترح', 'proposal' => $this->proposal()])->assertCreated()->json('data');
    }

    private function submit(array $r): array
    {
        return $this->postJson(self::API.'/requests/'.$r['id'].'/submit', ['revision' => $r['revision']])->assertOk()->json('data');
    }

    public function test_multi_item_need_interviews_return_resubmit_and_atomic_approval(): void
    {
        $counts = [DB::table('employees')->count(), DB::table('users')->count(), DB::table('payroll_employees')->count()];
        $c = $this->candidate();
        foreach (['scheduled', 'completed'] as $state) {
            $this->postJson(self::API.'/candidates/'.$c['id'].'/interviews', ['candidate_revision' => $c['revision'], 'scheduled_at' => '2026-10-02 10:00:00', 'status' => $state, 'participant_ids' => [20], 'evaluation' => 'تقييم اصطناعي', 'result' => 'ملائم'])->assertOk();
            $c = $this->getJson(self::API.'/candidates/'.$c['id'])->assertOk()->json('data.candidate');
        }
        self::assertSame(2, DB::table('hr_interviews')->count());
        $r = $this->draft($c['id']);
        self::assertSame($counts[0], DB::table('employees')->count());
        self::assertSame(0, DB::table('hr_employment_relationships')->count());
        $r = $this->submit($r);
        $r = $this->postJson(self::API.'/requests/'.$r['id'].'/decide', ['revision' => $r['revision'], 'decision' => 'return', 'note' => 'راجع التواريخ'])->assertOk()->json('data');
        self::assertSame('returned', $r['status']);
        $r = $this->submit($r);
        self::assertSame(2, $r['submission_version']);
        $this->postJson(self::API.'/requests/'.$r['id'].'/decide', ['revision' => $r['revision'], 'decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved');
        self::assertSame($counts[0] + 1, DB::table('employees')->count());
        self::assertSame($counts[1], DB::table('users')->count());
        self::assertSame($counts[2], DB::table('payroll_employees')->count());
        self::assertSame(1, DB::table('hr_employment_relationships')->count());
        $this->postJson(self::API.'/requests/'.$r['id'].'/decide', ['revision' => $r['revision'], 'decision' => 'approve'])->assertConflict();
        $items = $this->getJson(self::API.'/needs')->assertOk()->json('data.rows.0.items');
        self::assertSame(1, $items[0]['filled']);
        self::assertSame(1, $items[0]['remaining']);
        self::assertSame('دكتوراه', $items[1]['education']);
        self::assertGreaterThan(5, DB::table('hr_workforce_events')->count());
    }

    public function test_classification_preserves_existing_employee_dean_and_accounts_and_detects_aba(): void
    {
        $positions = DB::table('employee_positions')->get()->toArray();
        $users = DB::table('users')->count();
        $this->postJson(self::API.'/workers/6/classification', ['revision' => 1, 'reason' => 'توثيق يدوي للسجل الموجود', 'proposal' => $this->proposal(['starts_on' => '2026-01-01', 'ends_on' => '2026-03-31'])])->assertOk();
        self::assertEquals($positions, DB::table('employee_positions')->get()->toArray());
        self::assertSame($users, DB::table('users')->count());
        self::assertSame('legacy_classification', DB::table('hr_employment_relationships')->value('source'));
        self::assertNull(DB::table('hr_employment_relationships')->value('request_id'));
        $this->postJson(self::API.'/workers/6/classification', ['revision' => 2, 'reason' => 'مرة ثانية', 'proposal' => $this->proposal()])->assertConflict();
        DB::table('employees')->where('employee_id', 20)->update(['last_name' => 'Changed']);
        DB::table('employees')->where('employee_id', 20)->update(['last_name' => 'مدرس حالي']);
        self::assertSame(3, DB::table('employees')->where('employee_id', 20)->value('hr_revision'));
        $this->postJson(self::API.'/workers/20/classification', ['revision' => 1, 'reason' => 'مراجعة قديمة', 'proposal' => $this->proposal()])->assertConflict()->assertJsonPath('error_code', 'hr_stale');
    }

    public function test_conversion_preserves_dates_and_pending_request_does_not_replace_relation(): void
    {
        $this->postJson(self::API.'/workers/20/classification', ['revision' => 1, 'reason' => 'موجود', 'proposal' => $this->proposal()])->assertOk();
        $old = DB::table('hr_employment_relationships')->first();
        $p = $this->proposal(['relationship_type' => 'employment', 'work_mode' => 'full', 'starts_on' => '2027-01-01', 'ends_on' => null, 'predecessor_id' => $old->id, 'employee_number' => null]);
        $r = $this->postJson(self::API.'/requests', ['employee_id' => 20, 'kind' => 'convert', 'reason' => 'تحويل', 'proposal' => $p])->assertCreated()->json('data');
        self::assertSame(1, DB::table('hr_employment_relationships')->count());
        $r = $this->submit($r);
        $this->postJson(self::API.'/requests/'.$r['id'].'/decide', ['revision' => $r['revision'], 'decision' => 'approve'])->assertOk();
        self::assertSame(2, DB::table('hr_employment_relationships')->count());
        self::assertSame('2026-12-31', DB::table('hr_employment_relationships')->where('id', $old->id)->value('ends_on'));
        self::assertSame('2027-01-01', DB::table('hr_employment_relationships')->where('id', $old->id)->value('superseded_from'));
    }

    public function test_stale_context_and_invalid_dates_roll_back_without_issuing_person_or_relation(): void
    {
        $c = $this->candidate();
        $r = $this->submit($this->draft($c['id']));
        $people = DB::table('employees')->count();
        DB::table('hr_staffing_needs')->increment('revision');
        $this->postJson(self::API.'/requests/'.$r['id'].'/decide', ['revision' => $r['revision'], 'decision' => 'approve'])->assertConflict()->assertJsonPath('error_code', 'hr_context_stale');
        self::assertSame($people, DB::table('employees')->count());
        self::assertSame(0, DB::table('hr_employment_relationships')->count());
        self::assertSame('submitted', DB::table('hr_relationship_requests')->value('status'));
        $this->postJson(self::API.'/workers/20/classification', ['revision' => 1, 'reason' => 'مدة خاطئة', 'proposal' => $this->proposal(['ends_on' => '2026-11-30'])])->assertUnprocessable();
    }

    public function test_explicit_payroll_link_keeps_money_and_classification_and_rejects_conflict(): void
    {
        $body = DB::table('payroll_bodies')->insertGetId(['name' => 'تصنيف مالي موجود']);
        $pid = DB::table('payroll_employees')->insertGetId(['employee_number' => 'FIN-20', 'full_name' => 'اسم مالي محفوظ', 'job_title' => 'محفوظ', 'payroll_body_id' => $body, 'workplace' => 'afrin']);
        DB::table('payroll_entries')->insert(['payroll_employee_id' => $pid, 'fixed_salary_cents' => 98700]);
        $d = ['payroll_employee_id' => $pid, 'revision' => 1, 'payroll_revision' => 1, 'confirmed' => true, 'reason' => 'تحقق صريح من الهوية'];
        $this->postJson(self::API.'/workers/20/payroll-link', $d)->assertOk();
        self::assertSame(20, DB::table('payroll_employees')->value('employee_id'));
        self::assertSame('اسم مالي محفوظ', DB::table('payroll_employees')->value('full_name'));
        self::assertSame(98700, DB::table('payroll_entries')->value('fixed_salary_cents'));
        $d['payroll_revision'] = 2;
        $this->postJson(self::API.'/workers/6/payroll-link', $d)->assertConflict();
        self::assertSame(1, DB::table('payroll_employees')->count());
    }

    public function test_permissions_scope_review_and_schema_absence_are_enforced_over_http(): void
    {
        $this->getJson(self::API.'/options')->assertOk();
        foreach ([2, 3, 5, 6, 10, 11] as $id) {
            $this->login($id);
            $this->getJson(self::API.'/workers')->assertForbidden();
        }
        $this->login(1);
        $this->artisan('hr-office:provision-access', ['--grant-vp' => true])->assertExitCode(0);
        $this->login(2);
        $this->getJson(self::API.'/workers')->assertOk();
        self::assertFalse(User::find(2)->effectivePermissions()->contains(HrOffice::PAYROLL_ACCESS));
        $this->getJson('/api/v1/vice-presidency/administrative/payroll/sheet')->assertForbidden();
        $this->login(1);
        Schema::drop('hr_workforce_events');
        $this->getJson(self::API.'/options')->assertOk()->assertJsonPath('data.schema_ready', false);
        $this->getJson(self::API.'/workers')->assertStatus(503);
    }

    public function test_read_queries_do_not_write_and_unknown_input_is_rejected(): void
    {
        $this->need();
        $before = DB::table('hr_workforce_events')->count();
        $this->getJson(self::API.'/needs?q=')->assertOk();
        $this->getJson(self::API.'/workers')->assertOk();
        $this->getJson(self::API.'/workers/20')->assertOk();
        self::assertSame($before, DB::table('hr_workforce_events')->count());
        $this->getJson(self::API.'/workers?raw_column=x')->assertUnprocessable();
    }

    public function test_actual_hr_scope_is_required_and_hr_cannot_review_even_with_review_permission(): void
    {
        DB::table('roles')->insert(['role_id' => 30, 'role_code' => 'hr_officer', 'role_name' => 'Synthetic HR', 'is_active' => true]);
        DB::table('user_roles')->insert(['user_id' => 5, 'role_id' => 30, 'is_active' => true]);
        $this->artisan('hr-office:provision-access', ['--grant-hr' => true])->assertExitCode(0);
        $this->login(5);
        $this->getJson(self::API.'/workers')->assertForbidden();
        DB::table('user_access_scopes')->insert(['user_id' => 5, 'scope_type' => 'program', 'scope_id' => 1, 'is_active' => true]);
        $this->login(5);
        $this->getJson(self::API.'/workers')->assertForbidden();
        DB::table('user_access_scopes')->insert(['user_id' => 5, 'scope_type' => 'college', 'scope_id' => 1, 'is_active' => true]);
        $this->login(5);
        $this->getJson(self::API.'/workers')->assertOk();
        $this->getJson(self::API.'/workers/6')->assertOk();
        DB::table('employees')->insert(['employee_id' => 99, 'employee_number' => 'HR-OUTSIDE', 'first_name' => 'خارج', 'last_name' => 'النطاق', 'employee_type_id' => 1, 'employee_status_id' => 1, 'organizational_unit_id' => 11]);
        $this->getJson(self::API.'/workers/99')->assertForbidden();
        DB::table('role_permissions')->insert(['role_id' => 30, 'permission_id' => DB::table('permissions')->where('permission_code', HrOffice::REVIEW)->value('permission_id')]);
        DB::table('user_access_scopes')->insert(['user_id' => 5, 'scope_type' => 'university', 'scope_id' => 1, 'is_active' => true]);
        $this->login(5);
        $this->postJson(self::API.'/requests/1/decide', ['revision' => 1, 'decision' => 'approve'])->assertForbidden();
        $this->getJson('/api/v1/vice-presidency/administrative/payroll/sheet')->assertForbidden();
    }

    public function test_existing_person_is_reused_and_decline_rejection_keep_history(): void
    {
        $n = $this->need();
        $item = DB::table('hr_staffing_need_items')->where('need_id', $n['id'])->value('id');
        $e = DB::table('employees')->where('employee_id', 20)->first();
        $c = $this->postJson(self::API.'/candidates', ['need_item_id' => $item, 'employee_id' => 20, 'first_name' => $e->first_name, 'last_name' => $e->last_name])->assertCreated()->json('data');
        $r = $this->postJson(self::API.'/requests', ['candidate_id' => $c['id'], 'kind' => 'accept', 'reason' => 'ربط ملف قائم', 'proposal' => $this->proposal(['employee_number' => $e->employee_number])])->assertCreated()->json('data');
        $r = $this->submit($r);
        $before = DB::table('employees')->count();
        $this->postJson(self::API.'/requests/'.$r['id'].'/decide', ['revision' => $r['revision'], 'decision' => 'approve'])->assertOk();
        self::assertSame($before, DB::table('employees')->count());
        self::assertSame(20, DB::table('hr_employment_relationships')->value('employee_id'));
        $other = $this->candidate();
        $r = $this->submit($this->draft($other['id']));
        $this->postJson(self::API.'/requests/'.$r['id'].'/decide', ['revision' => $r['revision'], 'decision' => 'reject', 'note' => '  '])->assertUnprocessable();
        $this->postJson(self::API.'/requests/'.$r['id'].'/decide', ['revision' => $r['revision'], 'decision' => 'reject', 'note' => 'سبب اصطناعي'])->assertOk()->assertJsonPath('data.current_slot', null);
        $this->postJson(self::API.'/candidates/'.$other['id'].'/decline', ['revision' => $other['revision'], 'reason' => 'اعتذار مسجل'])->assertOk();
        self::assertSame(1, DB::table('hr_employment_relationships')->count());
    }

    public function test_duplicate_current_request_and_employee_identity_failure_roll_back_everything(): void
    {
        $c = $this->candidate();
        $draft = $this->draft($c['id']);
        $this->postJson(self::API.'/requests', ['candidate_id' => $c['id'], 'kind' => 'accept', 'reason' => 'مكرر', 'proposal' => $this->proposal()])->assertConflict();
        $r = $this->submit($draft);
        $count = DB::table('employees')->count();
        $events = DB::table('hr_workforce_events')->count();
        DB::table('employees')->where('employee_id', 20)->update(['employee_number' => 'HR-TEST-NEW']);
        $this->postJson(self::API.'/requests/'.$r['id'].'/decide', ['revision' => $r['revision'], 'decision' => 'approve'])->assertConflict();
        self::assertSame($count, DB::table('employees')->count());
        self::assertSame($events, DB::table('hr_workforce_events')->count());
        self::assertSame(0, DB::table('hr_employment_relationships')->count());
    }

    public function test_finance_entry_requires_its_own_assigned_permission_and_preserves_owner_gate(): void
    {
        $this->artisan('hr-office:provision-access')->assertExitCode(0);
        $this->artisan('owner-portal:provision-access')->assertExitCode(0);
        DB::table('roles')->insert(['role_id' => 31, 'role_code' => 'finance_officer', 'role_name' => 'Synthetic finance', 'is_active' => true]);
        DB::table('user_roles')->insert(['user_id' => 5, 'role_id' => 31, 'is_active' => true]);
        DB::table('user_access_scopes')->insert(['user_id' => 5, 'scope_type' => 'university', 'scope_id' => 1, 'is_active' => true]);
        foreach ([HrOffice::PAYROLL_ACCESS, 'owner_payroll.view'] as $p) {
            DB::table('role_permissions')->insert(['role_id' => 31, 'permission_id' => DB::table('permissions')->where('permission_code', $p)->value('permission_id')]);
        }
        $this->login(5);
        $this->getJson('/api/v1/vice-presidency/administrative/payroll/bodies')->assertOk();
        $this->postJson('/api/v1/vice-presidency/administrative/payroll/bodies', ['name' => 'unauthorized'])->assertForbidden();
        $this->getJson('/api/v1/owner/payroll/bodies')->assertForbidden();
        $this->getJson(self::API.'/workers')->assertForbidden();
        $this->login(1);
        $this->artisan('owner-portal:provision-access', ['--check' => true])->assertExitCode(0);
    }

    public function test_worker_query_count_is_bounded_and_migration_reapplication_retains_records(): void
    {
        DB::enableQueryLog();
        $this->getJson(self::API.'/workers?per_page=15')->assertOk();
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($id = 101; $id < 151; $id++) {
            DB::table('employees')->insert(['employee_id' => $id, 'employee_number' => 'HR-BOUND-'.$id, 'first_name' => 'اختبار', 'last_name' => 'تجميعي', 'employee_type_id' => 1, 'employee_status_id' => 1, 'organizational_unit_id' => 10]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(self::API.'/workers?per_page=100')->assertOk();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        self::assertLessThanOrEqual($small + 2, $large);
        $before = DB::table('employees')->count();
        $migration = require database_path('migrations/2026_10_09_100000_create_administrative_hr_office.php');
        $migration->up();
        self::assertSame($before, DB::table('employees')->count());
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
