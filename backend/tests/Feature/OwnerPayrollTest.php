<?php

namespace Tests\Feature;

use App\Support\OwnerPortal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * University-owner portal: access, isolated payroll data, employees, bodies, sheet queries and Home.
 * Real HTTP against an isolated in-memory SQLite database with synthetic data only.
 */
final class OwnerPayrollTest extends OwnerPayrollTestCase
{
    // ── access ────────────────────────────────────────────────────────────

    private function endpoints(): array
    {
        return [
            ['GET', 'home'], ['GET', 'payroll/options'], ['GET', 'payroll/sheet'], ['GET', 'payroll/bodies'], ['POST', 'payroll/bodies'], ['PATCH', 'payroll/bodies/1'],
            ['POST', 'payroll/bodies/1/deactivate'], ['DELETE', 'payroll/bodies/1'], ['POST', 'payroll/employees'], ['PATCH', 'payroll/employees/1'],
            ['PATCH', 'payroll/values'], ['GET', 'payroll/config'], ['POST', 'payroll/config/preview'], ['POST', 'payroll/config/columns'], ['PATCH', 'payroll/config/columns/fixed_salary'], ['DELETE', 'payroll/config/columns/fixed_salary'], ['PUT', 'payroll/config/layout'], ['PATCH', 'payroll/config/settings'], ['GET', 'payroll/export/xlsx'], ['GET', 'payroll/export/pdf'],
        ];
    }

    public function test_only_owner_and_central_administrator_reach_pages_apis_mutations_and_exports(): void
    {
        foreach ($this->endpoints() as [$method, $path]) {
            $this->json($method, self::API.'/'.$path)->assertUnauthorized();
        }
        // President, both VPs, HR, technical team, a plain account, a disabled owner, and a role with owner permissions mapped by mistake.
        foreach ([self::PRESIDENT, self::HR, self::TECH, self::VP_SCIENTIFIC, self::VP_ADMIN, self::OWNER_DISABLED, self::PLAIN, self::ROGUE] as $user) {
            $this->actingAsUser($user);
            foreach ($this->endpoints() as [$method, $path]) {
                $this->json($method, self::API.'/'.$path, ['name' => 'x'])->assertForbidden();
            }
        }
        $this->assertSame(0, DB::table('payroll_bodies')->count());

        foreach ([self::OWNER, self::ADMIN] as $user) {
            $this->actingAsUser($user);
            $this->getJson(self::API.'/home')->assertOk();
            $this->getJson(self::API.'/payroll/sheet')->assertOk();
            $this->getJson(self::API.'/payroll/export/xlsx')->assertOk();
            $this->getJson(self::API.'/payroll/export/pdf')->assertOk();
            $this->getJson(self::API.'/payroll/config')->assertOk();
        }
    }

    public function test_access_fails_closed_for_inactive_role_revoked_assignment_and_missing_permission(): void
    {
        $this->actingAsUser(self::OWNER);
        $this->getJson(self::API.'/home')->assertOk();

        // One permission removed from the role closes only that capability.
        $export = DB::table('permissions')->where('permission_code', OwnerPortal::EXPORT)->value('permission_id');
        DB::table('role_permissions')->where('role_id', 2)->where('permission_id', $export)->delete();
        $this->getJson(self::API.'/payroll/export/pdf')->assertForbidden();
        $this->getJson(self::API.'/payroll/sheet')->assertOk();

        // Without the access permission nothing opens.
        $access = DB::table('permissions')->where('permission_code', OwnerPortal::ACCESS)->value('permission_id');
        DB::table('role_permissions')->where('role_id', 2)->where('permission_id', $access)->delete();
        $this->getJson(self::API.'/home')->assertForbidden();
        DB::table('role_permissions')->insert(['role_id' => 2, 'permission_id' => $access]);
        $this->getJson(self::API.'/home')->assertOk();

        DB::table('roles')->where('role_id', 2)->update(['is_active' => 0]);
        $this->getJson(self::API.'/home')->assertForbidden();
        DB::table('roles')->where('role_id', 2)->update(['is_active' => 1]);
        DB::table('user_roles')->where('user_id', self::OWNER)->update(['is_active' => 0]);
        $this->getJson(self::API.'/home')->assertForbidden();
    }

    public function test_provisioning_is_idempotent_creates_no_users_or_assignments_and_refuses_conflicts(): void
    {
        // A foreign role carrying owner permissions (fixture misconfiguration) blocks provisioning, writing nothing.
        $this->assertSame(1, Artisan::call('owner-portal:provision-access'));
        DB::table('role_permissions')->where('role_id', 8)->delete();
        $before = ['users' => DB::table('users')->count(), 'user_roles' => DB::table('user_roles')->count(), 'roles' => DB::table('roles')->count()];
        $this->assertSame(0, Artisan::call('owner-portal:provision-access'));
        $this->assertSame($before, ['users' => DB::table('users')->count(), 'user_roles' => DB::table('user_roles')->count(), 'roles' => DB::table('roles')->count()]);
        $this->assertSame(count(OwnerPortal::PERMISSIONS), DB::table('role_permissions')->where('role_id', 2)->count());
        // The owner role is granted to nobody by default.
        $this->assertSame(2, DB::table('user_roles')->where('role_id', 2)->count(), 'only the two synthetic fixture owners hold the role');

        DB::table('permissions')->where('permission_code', OwnerPortal::EXPORT)->update(['is_active' => 0]);
        $this->assertSame(1, Artisan::call('owner-portal:provision-access'));
    }

    // ── employees: manual number, validation, isolation ───────────────────

    public function test_employee_number_is_manual_text_trimmed_and_preserves_leading_zeros(): void
    {
        $this->actingAsUser(self::OWNER);
        $body = $this->body();
        $row = $this->employee(['employee_number' => '  0007A-12  '], $body);
        $this->assertSame('0007A-12', $row['employee_number']);
        $this->assertSame('0007A-12', DB::table('payroll_employees')->value('employee_number'));
        $this->assertNull($row['cells']['fixed_salary']['v']);
        $this->assertNull($row['cells']['other_deductions']['v']);
        $this->assertNull($row['cells']['compensation']['v']);
        $this->assertSame('missing', $row['cells']['total_net_payable']['st']);
        $this->assertSame(0, DB::table('payroll_entry_values')->count(), 'a new employee stores no value at all: blank, never prefilled with zero');
        $this->assertSame(1, $row['employee_revision']);

        $this->postJson(self::API.'/payroll/employees', ['full_name' => 'بلا رقم', 'job_title' => 'x', 'body_id' => $body['id'], 'workplace' => 'afrin'])
            ->assertUnprocessable()->assertJsonPath('errors.employee_number.0', 'رقم الموظف مطلوب.');
        $this->postJson(self::API.'/payroll/employees', ['employee_number' => '=cmd|x', 'full_name' => 'x', 'job_title' => 'x', 'body_id' => $body['id'], 'workplace' => 'afrin'])
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['employee_number']]);
    }

    public function test_duplicate_numbers_are_rejected_by_the_server_and_the_database_constraint(): void
    {
        $this->actingAsUser(self::OWNER);
        $body = $this->body();
        $this->employee(['employee_number' => 'AB-001'], $body);
        foreach (['AB-001', ' AB-001 ', 'ab-001'] as $duplicate) {
            $this->postJson(self::API.'/payroll/employees', ['employee_number' => $duplicate, 'full_name' => 'مكرر', 'job_title' => 'x', 'body_id' => $body['id'], 'workplace' => 'afrin'])
                ->assertUnprocessable()->assertJsonPath('errors.employee_number.0', 'رقم الموظف مستخدم لموظف آخر.');
        }
        $this->assertSame(1, DB::table('payroll_employees')->count());
        // The DB constraint holds even if application validation were bypassed.
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('payroll_employees')->insert(['employee_number' => 'AB-001', 'full_name' => 'x', 'job_title' => 'x', 'payroll_body_id' => $body['id'], 'workplace' => 'afrin', 'revision' => 1]);
    }

    public function test_workplace_rules_and_clearing_obsolete_custom_location(): void
    {
        $this->actingAsUser(self::OWNER);
        $body = $this->body();
        foreach (['afrin' => 'عفرين', 'jarablus' => 'جرابلس', 'afrin_jarablus' => 'عفرين وجرابلس'] as $code => $label) {
            $row = $this->employee(['employee_number' => "W-{$code}", 'workplace' => $code, 'workplace_other' => 'ignored text'], $body);
            $this->assertSame($label, $row['workplace_label']);
            $this->assertNull($row['workplace_other'], 'custom text is not kept for a fixed workplace');
        }
        $this->postJson(self::API.'/payroll/employees', ['employee_number' => 'W-X', 'full_name' => 'x', 'job_title' => 'x', 'body_id' => $body['id'], 'workplace' => 'other'])
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['workplace_other']]);
        $this->postJson(self::API.'/payroll/employees', ['employee_number' => 'W-Y', 'full_name' => 'x', 'job_title' => 'x', 'body_id' => $body['id'], 'workplace' => 'idlib'])
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['workplace']]);

        $other = $this->employee(['employee_number' => 'W-O', 'workplace' => 'other', 'workplace_other' => '  إدلب  '], $body);
        $this->assertSame('إدلب', $other['workplace_other']);
        $this->assertSame('إدلب', $other['workplace_label']);
        $moved = $this->patchJson(self::API."/payroll/employees/{$other['id']}", [
            'expected_revision' => $other['employee_revision'], 'employee_number' => 'W-O', 'full_name' => 'موظف تجريبي', 'job_title' => 'محاسب',
            'body_id' => $body['id'], 'workplace' => 'jarablus', 'workplace_other' => 'إدلب',
        ])->assertOk()->json('data');
        $this->assertNull($moved['workplace_other']);
        $this->assertNull(DB::table('payroll_employees')->where('id', $other['id'])->value('workplace_other'));
    }

    public function test_academic_level_is_optional_free_text_distinct_from_job_title(): void
    {
        $this->actingAsUser(self::OWNER);
        $body = $this->body();
        $empty = $this->employee(['employee_number' => 'L-1', 'academic_level' => ''], $body);
        $this->assertNull($empty['academic_level']);
        $with = $this->employee(['employee_number' => 'L-2', 'job_title' => 'أستاذ مساعد', 'academic_level' => 'دكتوراه في الفيزياء'], $body);
        $this->assertSame('دكتوراه في الفيزياء', $with['academic_level']);
        $this->assertSame('أستاذ مساعد', $with['job_title']);
    }

    public function test_metadata_edit_with_revision_and_required_fields(): void
    {
        $this->actingAsUser(self::OWNER);
        $body = $this->body();
        $row = $this->employee(['employee_number' => 'E-1'], $body);
        $payload = ['employee_number' => 'E-1B', 'full_name' => 'اسم معدّل', 'job_title' => 'مدير', 'body_id' => $body['id'], 'workplace' => 'afrin_jarablus', 'academic_level' => 'ماجستير'];
        $updated = $this->patchJson(self::API."/payroll/employees/{$row['id']}", $payload + ['expected_revision' => 1])->assertOk()->json('data');
        $this->assertSame(2, $updated['employee_revision']);
        $this->assertSame('E-1B', $updated['employee_number']);
        $this->assertSame(1, $updated['entry_revision'], 'metadata edits never touch the financial revision');

        $this->patchJson(self::API."/payroll/employees/{$row['id']}", $payload + ['expected_revision' => 1])->assertStatus(409)->assertJsonPath('error_code', 'payroll_conflict')->assertJsonPath('data.current.employee_number', 'E-1B');
        $this->patchJson(self::API."/payroll/employees/{$row['id']}", ['expected_revision' => 2, 'employee_number' => 'E-1B'] + ['full_name' => '', 'job_title' => '', 'body_id' => null, 'workplace' => null])
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['full_name', 'job_title', 'body_id', 'workplace']]);
    }

    public function test_creating_a_payroll_employee_touches_no_personnel_account_or_role_tables(): void
    {
        $this->actingAsUser(self::OWNER);
        $body = $this->body();
        $tables = ['users', 'user_roles', 'employees', 'faculty_members', 'students', 'employee_positions', 'user_activity_logs', 'roles', 'role_permissions'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
        $before = $snapshot();
        $row = $this->employee(['employee_number' => 'ISO-1'], $body);
        $this->values([$this->change($row, ['fixed_salary' => '100'])])->assertOk();
        $this->assertSame($before, $snapshot());

        // Schema isolation: payroll tables reference only each other; audit user ids are plain integers.
        foreach (['payroll_bodies', 'payroll_employees', 'payroll_entries', 'payroll_config', 'payroll_settings', 'payroll_columns', 'payroll_entry_values'] as $table) {
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $this->assertStringStartsWith('payroll_', $foreignKey['foreign_table'], "{$table} must not reference personnel/user tables");
            }
        }
    }

    public function test_no_existing_application_code_reads_or_writes_the_payroll_tables(): void
    {
        // Workforce statistics, reports and workflows can never include payroll people because only payroll code names these tables.
        $allowed = ['Services/Payroll/', 'Models/Payroll/', 'Http/Controllers/Api/OwnerPayrollController.php', 'Support/PayrollWorkplace.php', 'Support/OwnerPortal.php'];
        $offenders = [];
        foreach (File::allFiles(app_path()) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $isAllowed = collect($allowed)->contains(fn ($prefix) => str_starts_with($relative, $prefix));
            if (! $isAllowed && preg_match('/payroll_(employees|entries|bodies|columns|settings|config|entry_values)|Models[\\\\]Payroll/', $file->getContents())) {
                $offenders[] = $relative;
            }
        }
        $this->assertSame([], $offenders);
    }

    // ── bodies ────────────────────────────────────────────────────────────

    public function test_body_management_rename_deactivate_reactivate_and_delete_rules(): void
    {
        $this->actingAsUser(self::OWNER);
        $this->getJson(self::API.'/payroll/bodies')->assertOk()->assertExactJson(['data' => []]);
        $a = $this->body('الهيئة الأولى');
        $b = $this->body('الهيئة الثانية');
        $this->postJson(self::API.'/payroll/bodies', ['name' => ' الهيئة الأولى '])->assertUnprocessable()->assertJsonPath('errors.name.0', 'توجد هيئة بهذا الاسم.');
        $this->postJson(self::API.'/payroll/bodies', ['name' => ''])->assertUnprocessable();

        $renamed = $this->patchJson(self::API."/payroll/bodies/{$a['id']}", ['name' => 'هيئة مُعاد تسميتها', 'expected_revision' => 1])->assertOk()->json('data');
        $this->assertSame(2, $renamed['revision']);
        $this->patchJson(self::API."/payroll/bodies/{$a['id']}", ['name' => 'أخرى', 'expected_revision' => 1])->assertStatus(409)->assertJsonPath('error_code', 'payroll_conflict');
        $this->patchJson(self::API."/payroll/bodies/{$a['id']}", ['name' => 'الهيئة الثانية', 'expected_revision' => 2])->assertUnprocessable();

        $employee = $this->employee(['employee_number' => 'B-1'], $a);
        $this->deleteJson(self::API."/payroll/bodies/{$a['id']}", ['expected_revision' => 2])->assertStatus(409)->assertJsonPath('error_code', 'payroll_body_in_use');
        $this->assertSame(1, DB::table('payroll_bodies')->where('id', $a['id'])->count());

        $off = $this->postJson(self::API."/payroll/bodies/{$a['id']}/deactivate", ['expected_revision' => 2])->assertOk()->json('data');
        $this->assertFalse($off['is_active']);
        // Existing assignment stays visible; new assignments to an inactive body are refused.
        $sheet = $this->getJson(self::API.'/payroll/sheet')->assertOk()->json('data');
        $this->assertSame('هيئة مُعاد تسميتها', $sheet[0]['body_name']);
        $this->assertFalse($sheet[0]['body_is_active']);
        $this->postJson(self::API.'/payroll/employees', ['employee_number' => 'B-2', 'full_name' => 'x', 'job_title' => 'x', 'body_id' => $a['id'], 'workplace' => 'afrin'])
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['body_id']]);
        $payload = ['employee_number' => 'B-1', 'full_name' => 'تعديل مع هيئة معطلة', 'job_title' => 'x', 'body_id' => $a['id'], 'workplace' => 'afrin'];
        $this->patchJson(self::API."/payroll/employees/{$employee['id']}", $payload + ['expected_revision' => 1])->assertOk();
        $this->patchJson(self::API."/payroll/employees/{$employee['id']}", ['body_id' => $b['id'], 'expected_revision' => 2] + $payload)->assertOk();
        $this->patchJson(self::API."/payroll/employees/{$employee['id']}", ['body_id' => $a['id'], 'expected_revision' => 3] + $payload)->assertUnprocessable();

        $on = $this->postJson(self::API."/payroll/bodies/{$a['id']}/activate", ['expected_revision' => 3])->assertOk()->json('data');
        $this->assertTrue($on['is_active']);
        $this->deleteJson(self::API."/payroll/bodies/{$a['id']}", ['expected_revision' => 4])->assertOk();
        $this->assertSame(1, DB::table('payroll_bodies')->count());
    }

    // ── sheet, filters, totals, home ──────────────────────────────────────

    private function seedSheet(): array
    {
        $one = $this->body('هيئة التدريس');
        $two = $this->body('هيئة الإدارة');
        $a = $this->employee(['employee_number' => '001', 'full_name' => 'سامر', 'job_title' => 'أستاذ', 'academic_level' => 'دكتوراه'], $one);
        $b = $this->employee(['employee_number' => '002', 'full_name' => 'ليلى', 'job_title' => 'مدير', 'workplace' => 'jarablus', 'academic_level' => 'ماجستير'], $two);
        $c = $this->employee(['employee_number' => '010', 'full_name' => 'نور', 'job_title' => 'محاسب', 'workplace' => 'other', 'workplace_other' => 'حلب'], $two);
        $d = $this->employee(['employee_number' => '011', 'full_name' => 'رامي', 'job_title' => 'سائق', 'workplace' => 'afrin_jarablus'], $two);
        $this->values([
            $this->change($a, ['fixed_salary' => '96600', 'compensation' => '55200']),
            $this->change($b, ['fixed_salary' => '20000', 'other_deductions' => '0']),
            $this->change($c, ['other_deductions' => '30', 'compensation' => '10']),   // blank fixed salary => net payable unavailable
        ])->assertOk();

        return [$one, $two, $a, $b, $c, $d];
    }

    private function numbers(string $query): array
    {
        return array_column($this->getJson(self::API.'/payroll/sheet'.$query)->assertOk()->json('data'), 'employee_number');
    }

    public function test_sheet_filters_sorting_and_totals_follow_all_matching_rows(): void
    {
        $this->actingAsUser(self::OWNER);
        [$one, $two] = $this->seedSheet();

        $all = $this->getJson(self::API.'/payroll/sheet')->assertOk();
        $this->assertSame(['001', '002', '010', '011'], array_column($all->json('data'), 'employee_number'));
        $net = $all->json('meta.totals.columns.total_net_payable');
        $this->assertSame(['sum' => '142860.30', 'excluded' => 2], $net, 'the unavailable rows are excluded and counted, never zero-filled');
        $this->assertSame(4, $all->json('meta.totals.employees'));
        $this->assertSame(2, $all->json('meta.totals.complete'));
        $this->assertSame(2, $all->json('meta.totals.incomplete'));
        $this->assertSame([], $all->json('meta.scope_labels'));
        $this->assertSame($this->configRevision(), $all->json('meta.config_revision'));

        $filtered = $this->getJson(self::API.'/payroll/sheet?body_id='.$two['id'])->json();
        $this->assertSame(['sum' => '17694.00', 'excluded' => 2], $filtered['meta']['totals']['columns']['total_net_payable']);
        $this->assertSame(['الهيئة: هيئة الإدارة'], $filtered['meta']['scope_labels']);

        $this->assertSame(['010'], $this->numbers('?workplace=other'));
        $this->assertSame(['001'], $this->numbers('?academic_level='.urlencode('دكتوراه')));
        $this->assertSame(['001', '011'], $this->numbers('?search='.urlencode('عفرين')), 'search matches the workplace label');
        $this->assertSame(['010'], $this->numbers('?search='.urlencode('حلب')));
        $this->assertSame([], $this->numbers('?search='.urlencode('%')), 'LIKE wildcards are literal');
        $this->assertSame(['010', '011'], $this->numbers('?completeness=incomplete'));
        $this->assertSame(['001', '002'], $this->numbers('?completeness=complete'));
        $this->assertSame([], $this->numbers('?completeness=warning'));

        // Sorting by identity columns and by any configured column; blanks/unavailable always last; ties break on the number.
        $this->assertSame(['001', '002', '010', '011'], $this->numbers('?sort=total_net_payable&direction=desc'));
        $this->assertSame(['002', '001', '010', '011'], $this->numbers('?sort=total_net_payable&direction=asc'));
        $this->assertSame(['011', '010', '002', '001'], $this->numbers('?sort=employee_number&direction=desc'));
        $this->assertSame(['001', '002', '010', '011'], $this->numbers('?sort=fixed_salary&direction=desc'));
        $this->getJson(self::API.'/payroll/sheet?sort=password')->assertUnprocessable();
        $this->getJson(self::API.'/payroll/sheet?workplace=nowhere')->assertUnprocessable();
    }

    public function test_blank_academic_level_filter_cannot_collide_with_user_text(): void
    {
        // Review finding: the "no level" filter used a sentinel string that a real level could equal.
        $this->actingAsUser(self::OWNER);
        $body = $this->body();
        $this->employee(['employee_number' => 'B1', 'academic_level' => '__blank__'], $body);
        $this->employee(['employee_number' => 'B2', 'academic_level' => null], $body);
        $this->employee(['employee_number' => 'B3', 'academic_level' => 'ماجستير'], $body);

        $this->assertSame(['B1'], $this->numbers('?academic_level=__blank__'), 'a level that happens to read __blank__ is an ordinary value');
        $this->assertSame(['B2'], $this->numbers('?academic_level_blank=1'));
        $this->getJson(self::API.'/payroll/sheet?academic_level_blank=1&academic_level='.urlencode('ماجستير'))->assertUnprocessable();
        $labels = $this->getJson(self::API.'/payroll/sheet?academic_level_blank=1')->json('meta.scope_labels');
        $this->assertSame(['المستوى الأكاديمي: غير محدد'], $labels);
    }

    public function test_employee_save_locks_the_body_inside_the_transaction_so_deactivation_cannot_slip_between_check_and_write(): void
    {
        // Review finding: body active state was validated outside the writing transaction. The body is now read FOR UPDATE
        // within the same transaction as the insert/update (verified here by the order of statements on the connection).
        $this->actingAsUser(self::OWNER);
        $body = $this->body();
        $log = [];
        DB::listen(function ($query) use (&$log) {
            $log[] = [strtolower(str_replace('`', '"', preg_replace('/\s+/', ' ', $query->sql))), DB::transactionLevel()];
        });
        $this->employee(['employee_number' => 'LOCK-1'], $body);
        $reads = array_keys(array_filter($log, fn ($q) => str_contains($q[0], 'from "payroll_bodies"') && str_contains($q[0], '"id" = ?')));
        $insert = array_keys(array_filter($log, fn ($q) => str_contains($q[0], 'insert into "payroll_employees"')));
        $this->assertNotEmpty($reads);
        $this->assertCount(1, $insert);
        $this->assertLessThan($insert[0], $reads[0], 'the body is read before the employee insert');
        $this->assertGreaterThanOrEqual(1, $log[$reads[0]][1], 'and inside the writing transaction (FOR UPDATE on MariaDB; see the concurrency script)');
        $this->assertTrue(DB::table('payroll_employees')->where('employee_number', 'LOCK-1')->exists());

        // The result of a validation that happens after a concurrent deactivation is a clean refusal.
        $this->postJson(self::API."/payroll/bodies/{$body['id']}/deactivate", ['expected_revision' => 1])->assertOk();
        $this->postJson(self::API.'/payroll/employees', ['employee_number' => 'LOCK-2', 'full_name' => 'x', 'job_title' => 'x', 'body_id' => $body['id'], 'workplace' => 'afrin'])->assertUnprocessable();
        $this->assertFalse(DB::table('payroll_employees')->where('employee_number', 'LOCK-2')->exists());
    }

    public function test_home_is_a_summary_of_the_same_numbers_as_the_grid_and_never_shows_incomplete_as_zero(): void
    {
        $this->actingAsUser(self::OWNER);
        $empty = $this->getJson(self::API.'/home')->assertOk()->json('data');
        $this->assertSame(0, $empty['employees']);
        $this->assertSame('0.00', $empty['totals']['net_payable']);
        $this->assertSame('SYP', $empty['currency']['code']);
        $this->assertSame('ل.س', $empty['currency']['symbol']);

        [$one, $two, , , $c, $d] = $this->seedSheet();
        $grid = $this->getJson(self::API.'/payroll/sheet')->json('meta.totals');
        $home = $this->getJson(self::API.'/home')->assertOk()->json('data');
        $this->assertSame($grid['columns']['total_net_payable']['sum'], $home['totals']['net_payable']);
        $this->assertSame($grid['columns']['total_deductions']['sum'], $home['totals']['total_deductions']);
        $this->assertSame($grid['columns']['gross_entitlement']['sum'], $home['totals']['gross_entitlement']);
        $this->assertSame($grid['columns']['insurance']['sum'], $home['totals']['insurance']);
        $this->assertSame(['employees' => 4, 'complete' => 2, 'incomplete' => 2], ['employees' => $home['employees'], 'complete' => $home['complete'], 'incomplete' => $home['incomplete']]);
        $this->assertSame(2, $home['excluded']['net_payable'], 'the headline says how many records it leaves out');

        $byBody = collect($home['by_body'])->keyBy('value');
        $this->assertEquals(['employees' => 3, 'incomplete' => 2, 'net_payable' => '17694.00', 'filter' => 'body_id'], collect($byBody[(string) $two['id']])->only(['employees', 'incomplete', 'net_payable', 'filter'])->all());
        $this->assertSame('125166.30', $byBody[(string) $one['id']]['net_payable']);
        $this->assertSame(['afrin', 'jarablus', 'other', 'afrin_jarablus'], collect($home['by_workplace'])->pluck('value')->sortBy(fn ($v) => array_search($v, ['afrin', 'jarablus', 'other', 'afrin_jarablus']))->values()->all());

        $this->assertSame(2, $home['attention']['total']);
        $ids = array_column($home['attention']['items'], 'employee_id');
        $this->assertEqualsCanonicalizing([$c['id'], $d['id']], $ids);
        $first = collect($home['attention']['items'])->firstWhere('employee_id', $d['id']);
        $this->assertSame('incomplete', $first['status']);
        $this->assertStringContainsString('الأجر المقطوع', $first['issues'][0]['message']);
    }
}
