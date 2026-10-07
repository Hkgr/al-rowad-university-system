<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\OwnerPortal;
use App\Support\PayrollMoney;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * University-owner portal: access, isolated payroll data, calculation rules, concurrency and exports.
 * Real HTTP against an isolated in-memory SQLite database with synthetic data only.
 */
final class OwnerPayrollTest extends TestCase
{
    private const API = '/api/v1/owner';

    private const ADMIN = 1;

    private const OWNER = 2;

    private const PRESIDENT = 3;

    private const HR = 4;

    private const TECH = 5;

    private const VP_SCIENTIFIC = 6;

    private const VP_ADMIN = 7;

    private const OWNER_DISABLED = 8;

    private const PLAIN = 9;

    private const ROGUE = 10;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['account_statuses' => fn (Blueprint $t) => [$t->integer('account_status_id')->primary(), $t->string('status_code'), $t->string('status_name')->nullable(), $t->boolean('is_active')->default(true), $t->timestamps()],
            'users' => fn (Blueprint $t) => [$t->integer('user_id')->primary(), $t->string('username'), $t->string('email'), $t->string('password_hash'), $t->integer('account_status_id'), $t->integer('student_id')->nullable(), $t->integer('employee_id')->nullable(), $t->integer('board_member_id')->nullable(), $t->dateTime('last_login_at')->nullable(), $t->dateTime('email_verified_at')->nullable(), $t->integer('failed_login_attempts')->default(0), $t->integer('created_by_user_id')->nullable(), $t->timestamps()],
            'system_modules' => fn (Blueprint $t) => [$t->increments('module_id'), $t->string('module_code')->unique(), $t->string('module_name'), $t->string('description')->nullable(), $t->boolean('is_active')->default(true), $t->timestamps()],
            'roles' => fn (Blueprint $t) => [$t->increments('role_id'), $t->string('role_code')->unique(), $t->string('role_name'), $t->string('description')->nullable(), $t->boolean('is_system_role')->default(false), $t->boolean('is_active')->default(true), $t->timestamps()],
            'permissions' => fn (Blueprint $t) => [$t->increments('permission_id'), $t->integer('module_id'), $t->string('permission_code')->unique(), $t->string('permission_name'), $t->string('description')->nullable(), $t->boolean('is_active')->default(true), $t->timestamps()],
            'role_permissions' => fn (Blueprint $t) => [$t->increments('role_permission_id'), $t->integer('role_id'), $t->integer('permission_id'), $t->dateTime('granted_at')->nullable()],
            'user_roles' => fn (Blueprint $t) => [$t->increments('user_role_id'), $t->integer('user_id'), $t->integer('role_id'), $t->integer('assigned_by_user_id')->nullable(), $t->dateTime('assigned_at')->nullable(), $t->boolean('is_active')->default(true)],
            // Existing personnel-like tables: payroll must never write to them.
            'employees' => fn (Blueprint $t) => [$t->increments('employee_id'), $t->string('employee_number'), $t->string('first_name')],
            'faculty_members' => fn (Blueprint $t) => [$t->increments('faculty_member_id'), $t->integer('employee_id')],
            'students' => fn (Blueprint $t) => [$t->increments('student_id'), $t->string('student_number')],
            'employee_positions' => fn (Blueprint $t) => [$t->increments('employee_position_id'), $t->integer('employee_id')],
            'user_activity_logs' => fn (Blueprint $t) => [$t->increments('id'), $t->integer('user_id')->nullable()],
        ] as $table => $definition) {
            Schema::create($table, $definition);
        }
        (require base_path('database/migrations/2026_10_08_000000_create_owner_payroll_tables.php'))->up();

        DB::table('account_statuses')->insert([['account_status_id' => 1, 'status_code' => 'active'], ['account_status_id' => 2, 'status_code' => 'disabled']]);
        foreach (['super_admin', 'university_owner', 'university_president', 'hr_officer', 'technical_team', 'vice_president_scientific', 'vice_president_administrative', 'rogue_role'] as $i => $code) {
            DB::table('roles')->insert(['role_id' => $i + 1, 'role_code' => $code, 'role_name' => $code, 'is_system_role' => 1]);
        }
        $users = [self::ADMIN => [1, 1], self::OWNER => [1, 2], self::PRESIDENT => [1, 3], self::HR => [1, 4], self::TECH => [1, 5], self::VP_SCIENTIFIC => [1, 6],
            self::VP_ADMIN => [1, 7], self::OWNER_DISABLED => [2, 2], self::PLAIN => [1, null], self::ROGUE => [1, 8]];
        foreach ($users as $id => [$status, $role]) {
            DB::table('users')->insert(['user_id' => $id, 'username' => "synthetic{$id}", 'email' => "synthetic{$id}@example.invalid", 'password_hash' => 'x', 'account_status_id' => $status]);
            if ($role !== null) {
                DB::table('user_roles')->insert(['user_id' => $id, 'role_id' => $role, 'is_active' => 1]);
            }
        }
        $this->assertSame(0, Artisan::call('owner-portal:provision-access'));
        // Rogue: another role carrying every owner permission by (mis)configuration.
        foreach (DB::table('permissions')->pluck('permission_id') as $permissionId) {
            DB::table('role_permissions')->insert(['role_id' => 8, 'permission_id' => $permissionId]);
        }
    }

    protected function tearDown(): void
    {
        // The fixture creates its own tables; a persistent engine (MariaDB runs) must start every test clean.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::disableForeignKeyConstraints();
            Schema::dropAllTables();
        }
        parent::tearDown();
    }

    private function actingAsUser(int $id): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::findOrFail($id));
    }

    private function body(string $name = 'هيئة اختبار أ'): array
    {
        return $this->postJson(self::API.'/payroll/bodies', ['name' => $name])->assertCreated()->json('data');
    }

    private function employee(array $overrides = [], ?array $body = null): array
    {
        $body ??= DB::table('payroll_bodies')->first() ? (array) DB::table('payroll_bodies')->first() : $this->body();

        return $this->postJson(self::API.'/payroll/employees', $overrides + [
            'employee_number' => '00123', 'full_name' => 'موظف تجريبي', 'job_title' => 'محاسب', 'body_id' => $body['id'],
            'workplace' => 'afrin', 'academic_level' => null,
        ])->assertCreated()->json('data');
    }

    private function amounts(array $changes)
    {
        return $this->patchJson(self::API.'/payroll/amounts', ['changes' => $changes]);
    }

    private function change(array $row, array $values): array
    {
        return ['employee_id' => $row['id'], 'expected_revision' => $row['entry_revision']] + $values;
    }

    // ── access ────────────────────────────────────────────────────────────

    private function endpoints(): array
    {
        return [
            ['GET', 'home'], ['GET', 'payroll/options'], ['GET', 'payroll/sheet'], ['GET', 'payroll/bodies'], ['POST', 'payroll/bodies'], ['PATCH', 'payroll/bodies/1'],
            ['POST', 'payroll/bodies/1/deactivate'], ['DELETE', 'payroll/bodies/1'], ['POST', 'payroll/employees'], ['PATCH', 'payroll/employees/1'],
            ['PATCH', 'payroll/amounts'], ['GET', 'payroll/export/xlsx'], ['GET', 'payroll/export/pdf'],
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
        $this->assertNull($row['fixed_salary']);
        $this->assertNull($row['deduction']);
        $this->assertNull($row['compensation']);
        $this->assertNull($row['payable']);
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
        $this->amounts([$this->change($row, ['fixed_salary' => '100'])])->assertOk();
        $this->assertSame($before, $snapshot());

        // Schema isolation: payroll tables reference only each other; audit user ids are plain integers.
        foreach (['payroll_bodies', 'payroll_employees', 'payroll_entries'] as $table) {
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $this->assertStringStartsWith('payroll_', $foreignKey['foreign_table'], "{$table} must not reference personnel/user tables");
            }
        }
    }

    public function test_no_existing_application_code_reads_or_writes_the_payroll_tables(): void
    {
        // Workforce statistics, reports and workflows can never include payroll people because only payroll code names these tables.
        $allowed = ['Services/Payroll/', 'Models/Payroll/', 'Http/Controllers/Api/OwnerPayrollController.php', 'Support/PayrollMoney.php', 'Support/PayrollWorkplace.php', 'Support/OwnerPortal.php'];
        $offenders = [];
        foreach (File::allFiles(app_path()) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $isAllowed = collect($allowed)->contains(fn ($prefix) => str_starts_with($relative, $prefix));
            if (! $isAllowed && preg_match('/payroll_(employees|entries|bodies)|Models[\\\\]Payroll/', $file->getContents())) {
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

    // ── amounts ───────────────────────────────────────────────────────────

    public function test_blank_versus_zero_exact_cents_and_negative_payable(): void
    {
        $this->actingAsUser(self::OWNER);
        $row = $this->employee();
        $this->assertNull($row['payable']);

        // Blank salary: payable stays blank even with deduction/compensation entered.
        $r = $this->amounts([$this->change($row, ['deduction' => '10', 'compensation' => '5.5'])])->assertOk()->json('data.0');
        $this->assertNull($r['fixed_salary']);
        $this->assertNull($r['payable']);
        $this->assertSame('10.00', $r['deduction']);
        $this->assertSame('5.50', $r['compensation']);

        // Salary only: blank deduction/compensation count as zero but stay blank (null).
        $other = $this->employee(['employee_number' => 'Z-2']);
        $r = $this->amounts([$this->change($other, ['fixed_salary' => '100.10'])])->assertOk()->json('data.0');
        $this->assertSame('100.10', $r['payable']);
        $this->assertNull($r['deduction']);
        $this->assertNull(DB::table('payroll_entries')->where('payroll_employee_id', $other['id'])->value('deduction_cents'));

        // Explicit zero is stored as 0 and stays distinguishable from blank.
        $r = $this->amounts([$this->change($r, ['deduction' => '0', 'compensation' => '0.00'] + ['employee_id' => $other['id']])])->assertOk()->json('data.0');
        $this->assertSame('0.00', $r['deduction']);
        $this->assertSame(0, (int) DB::table('payroll_entries')->where('payroll_employee_id', $other['id'])->value('deduction_cents'));
        $this->assertNotNull(DB::table('payroll_entries')->where('payroll_employee_id', $other['id'])->value('compensation_cents'));

        // Exact cents: 0.10 + 0.20 - 0.30 has no binary-float drift.
        $r = $this->amounts([$this->change($r, ['fixed_salary' => '0.10', 'deduction' => '0.30', 'compensation' => '0.20'] + ['employee_id' => $other['id']])])->assertOk()->json('data.0');
        $this->assertSame('0.00', $r['payable']);
        $this->assertSame(10, (int) DB::table('payroll_entries')->where('payroll_employee_id', $other['id'])->value('fixed_salary_cents'));
        $this->assertSame('0.00', PayrollMoney::format(PayrollMoney::payable(10, 30, 20)));

        // Negative net payable is shown with its sign, never clamped.
        $r = $this->amounts([$this->change($r, ['fixed_salary' => '100', 'deduction' => '250.75', 'compensation' => '0.25'] + ['employee_id' => $other['id']])])->assertOk()->json('data.0');
        $this->assertSame('-150.50', $r['payable']);

        // Clearing a cell returns it to blank.
        $r = $this->amounts([$this->change($r, ['deduction' => null] + ['employee_id' => $other['id']])])->assertOk()->json('data.0');
        $this->assertNull($r['deduction']);
        $this->assertSame('100.25', $r['payable']);
    }

    public function test_invalid_amounts_are_rejected_without_writing_anything(): void
    {
        $this->actingAsUser(self::OWNER);
        $a = $this->employee(['employee_number' => 'V-1']);
        $b = $this->employee(['employee_number' => 'V-2']);
        foreach (['-1', '-0.01', '1e3', '12.345', 'abc', '1,000', '٣٠', '1000000000', '+5', '1.', '.5', true, 1.5, ['x']] as $bad) {
            $response = $this->amounts([$this->change($a, ['fixed_salary' => '5']), $this->change($b, ['deduction' => $bad])])->assertUnprocessable();
            $response->assertJsonStructure(['errors' => ['changes.1.deduction']]);
        }
        $this->assertSame(0, DB::table('payroll_entries')->whereNotNull('fixed_salary_cents')->count(), 'the valid half of an invalid batch is not committed');
        $this->amounts([])->assertUnprocessable();
        $this->amounts([['employee_id' => $a['id'], 'expected_revision' => 1]])->assertUnprocessable();
        $this->amounts([$this->change($a, ['fixed_salary' => '1']), $this->change($a, ['deduction' => '1'])])->assertUnprocessable();

        // The payable is never accepted from the client.
        $r = $this->amounts([$this->change($a, ['fixed_salary' => '10', 'payable' => '9999'])])->assertOk()->json('data.0');
        $this->assertSame('10.00', $r['payable']);
    }

    public function test_stale_revision_produces_conflict_and_batch_is_all_or_nothing(): void
    {
        $this->actingAsUser(self::OWNER);
        $a = $this->employee(['employee_number' => 'C-1']);
        $b = $this->employee(['employee_number' => 'C-2']);
        $this->amounts([$this->change($a, ['fixed_salary' => '100'])])->assertOk();

        $response = $this->amounts([$this->change($a, ['fixed_salary' => '999']), $this->change($b, ['fixed_salary' => '50'])])->assertStatus(409);
        $response->assertJsonPath('error_code', 'payroll_conflict')->assertJsonPath('data.conflicts.0.employee_id', $a['id'])
            ->assertJsonPath('data.conflicts.0.current.fixed_salary', '100.00');
        $this->assertNull(DB::table('payroll_entries')->where('payroll_employee_id', $b['id'])->value('fixed_salary_cents'), 'the non-stale half is not committed');
        $this->assertSame(10000, (int) DB::table('payroll_entries')->where('payroll_employee_id', $a['id'])->value('fixed_salary_cents'));
        $this->amounts([['employee_id' => 9999, 'expected_revision' => 1, 'fixed_salary' => '1']])->assertNotFound();
    }

    public function test_multi_cell_batch_is_atomic_and_uses_stable_ids_after_sorting_and_filtering(): void
    {
        $this->actingAsUser(self::OWNER);
        $one = $this->body('الهيئة ألف');
        $two = $this->body('الهيئة باء');
        $x = $this->employee(['employee_number' => '010', 'full_name' => 'جيم'], $one);
        $y = $this->employee(['employee_number' => '002', 'full_name' => 'ألف', 'workplace' => 'jarablus'], $two);
        $z = $this->employee(['employee_number' => '003', 'full_name' => 'باء'], $two);

        $rows = $this->getJson(self::API.'/payroll/sheet?sort=full_name&direction=desc&body_id='.$two['id'])->assertOk()->json('data');
        $this->assertSame([$z['id'], $y['id']], array_column($rows, 'id'));
        // Edit using the id of the *visible second* row; the first-created employee must remain untouched.
        $this->amounts([$this->change($rows[1], ['fixed_salary' => '700', 'deduction' => '20'])])->assertOk();
        $this->assertSame(70000, (int) DB::table('payroll_entries')->where('payroll_employee_id', $y['id'])->value('fixed_salary_cents'));
        $this->assertNull(DB::table('payroll_entries')->where('payroll_employee_id', $x['id'])->value('fixed_salary_cents'));

        $this->amounts([$this->change($x, ['fixed_salary' => '1']), $this->change(array_merge($y, ['entry_revision' => 2]), ['fixed_salary' => '2']), $this->change($z, ['compensation' => '3'])])->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame([100, 200, null], [(int) DB::table('payroll_entries')->where('payroll_employee_id', $x['id'])->value('fixed_salary_cents'), (int) DB::table('payroll_entries')->where('payroll_employee_id', $y['id'])->value('fixed_salary_cents'), DB::table('payroll_entries')->where('payroll_employee_id', $z['id'])->value('fixed_salary_cents')]);
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
        $this->amounts([
            $this->change($a, ['fixed_salary' => '1000.50', 'deduction' => '100.25', 'compensation' => '50']),
            $this->change($b, ['fixed_salary' => '800', 'deduction' => '0']),
            $this->change($c, ['deduction' => '30', 'compensation' => '10']),   // blank salary => blank payable
        ])->assertOk();

        return [$one, $two, $a, $b, $c, $d];
    }

    public function test_sheet_filters_sorting_and_totals_follow_all_matching_rows(): void
    {
        $this->actingAsUser(self::OWNER);
        [$one, $two] = $this->seedSheet();

        $all = $this->getJson(self::API.'/payroll/sheet')->assertOk();
        $this->assertSame(['001', '002', '010', '011'], array_column($all->json('data'), 'employee_number'));
        $this->assertSame(['employees' => 4, 'fixed_salary' => '1800.50', 'deduction' => '130.25', 'compensation' => '60.00', 'payable' => '1750.25'], $all->json('meta.totals'));
        $this->assertSame([], $all->json('meta.scope_labels'));

        $filtered = $this->getJson(self::API.'/payroll/sheet?body_id='.$two['id'])->json();
        $this->assertSame(['employees' => 3, 'fixed_salary' => '800.00', 'deduction' => '30.00', 'compensation' => '10.00', 'payable' => '800.00'], $filtered['meta']['totals']);
        $this->assertSame(['الهيئة: هيئة الإدارة'], $filtered['meta']['scope_labels']);

        $this->assertSame(['010'], array_column($this->getJson(self::API.'/payroll/sheet?workplace=other')->json('data'), 'employee_number'));
        $this->assertSame(['001'], array_column($this->getJson(self::API.'/payroll/sheet?academic_level=دكتوراه')->json('data'), 'employee_number'));
        $this->assertSame(['010', '011'], array_column($this->getJson(self::API.'/payroll/sheet?academic_level=__blank__')->json('data'), 'employee_number'));
        $this->assertSame(['001', '011'], array_column($this->getJson(self::API.'/payroll/sheet?search='.urlencode('عفرين'))->json('data'), 'employee_number'), 'search matches the workplace label');
        $this->assertSame(['010'], array_column($this->getJson(self::API.'/payroll/sheet?search='.urlencode('حلب'))->json('data'), 'employee_number'));
        $this->assertSame(['010'], array_column($this->getJson(self::API.'/payroll/sheet?search=010')->json('data'), 'employee_number'));
        $this->assertSame([], $this->getJson(self::API.'/payroll/sheet?search='.urlencode('%'))->json('data'), 'LIKE wildcards are literal');

        // Sorting: blanks always last; ties break on employee number.
        $this->assertSame(['001', '002', '010', '011'], array_column($this->getJson(self::API.'/payroll/sheet?sort=payable&direction=desc')->json('data'), 'employee_number'));
        $this->assertSame(['002', '001', '010', '011'], array_column($this->getJson(self::API.'/payroll/sheet?sort=payable&direction=asc')->json('data'), 'employee_number'));
        $this->assertSame(['011', '010', '002', '001'], array_column($this->getJson(self::API.'/payroll/sheet?sort=employee_number&direction=desc')->json('data'), 'employee_number'));
        $this->getJson(self::API.'/payroll/sheet?sort=password')->assertUnprocessable();
        $this->getJson(self::API.'/payroll/sheet?workplace=nowhere')->assertUnprocessable();
    }

    public function test_home_grid_and_export_totals_match(): void
    {
        $this->actingAsUser(self::OWNER);
        $this->assertSame(['employees' => 0, 'fixed_salary' => '0.00', 'deduction' => '0.00', 'compensation' => '0.00', 'payable' => '0.00'],
            collect($this->getJson(self::API.'/home')->json('data'))->only(['employees', 'fixed_salary', 'deduction', 'compensation', 'payable'])->all());
        $this->seedSheet();
        $grid = $this->getJson(self::API.'/payroll/sheet')->json('meta.totals');
        $home = $this->getJson(self::API.'/home')->assertOk()->json('data');
        $this->assertSame($grid, collect($home)->only(['employees', 'fixed_salary', 'deduction', 'compensation', 'payable'])->all());
        $this->assertSame(2, $home['bodies']);

        $sheet = $this->loadXlsx($this->getJson(self::API.'/payroll/export/xlsx'));
        $last = $sheet->getHighestRow();
        // Totals row: formulas whose computed values equal the grid totals.
        $this->assertSame((float) $grid['fixed_salary'], (float) $sheet->getCell("G{$last}")->getCalculatedValue());
        $this->assertSame((float) $grid['deduction'], (float) $sheet->getCell("H{$last}")->getCalculatedValue());
        $this->assertSame((float) $grid['compensation'], (float) $sheet->getCell("I{$last}")->getCalculatedValue());
        $this->assertSame((float) $grid['payable'], (float) $sheet->getCell("J{$last}")->getCalculatedValue());
    }

    // ── exports ───────────────────────────────────────────────────────────

    private function loadXlsx($response): Worksheet
    {
        $response->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        $copy = tempnam(sys_get_temp_dir(), 'payroll_test_').'.xlsx';
        copy($file, $copy);
        $this->assertSame('PK', substr(file_get_contents($copy), 0, 2), 'a real zip-based .xlsx');

        return IOFactory::load($copy)->getActiveSheet();
    }

    public function test_excel_export_has_rtl_text_numbers_formulas_and_literal_user_text(): void
    {
        $this->actingAsUser(self::OWNER);
        $body = $this->body('=HYPERLINK("http://x","هيئة")');
        $a = $this->employee(['employee_number' => '00007', 'full_name' => '=1+1', 'job_title' => '+SUM(A1)', 'academic_level' => '@cmd'], $body);
        $b = $this->employee(['employee_number' => '00123', 'full_name' => 'ثاني'], $body);
        $this->amounts([$this->change($a, ['fixed_salary' => '1000.5', 'deduction' => '0', 'compensation' => '25']), $this->change($b, ['deduction' => '5'])])->assertOk();

        $response = $this->getJson(self::API.'/payroll/export/xlsx');
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $sheet = $this->loadXlsx($response);

        $this->assertTrue($sheet->getRightToLeft());
        $this->assertSame('رقم الموظف', $sheet->getCell('A5')->getValue());
        $this->assertSame('المستحق $', $sheet->getCell('J5')->getValue());
        $this->assertSame('الراتب المقطوع $', $sheet->getCell('G5')->getValue());
        $this->assertStringContainsString('كل الصفوف دون مرشحات', $sheet->getCell('A2')->getValue());

        // Employee numbers are text with leading zeros preserved.
        $this->assertSame('00007', $sheet->getCell('A6')->getValue());
        $this->assertSame('00123', $sheet->getCell('A7')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A6')->getDataType());
        // User text that looks like a formula stays literal text.
        foreach (['B6' => '=1+1', 'C6' => '+SUM(A1)', 'F6' => '@cmd', 'D6' => '=HYPERLINK("http://x","هيئة")'] as $cell => $text) {
            $this->assertSame($text, $sheet->getCell($cell)->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($cell)->getDataType(), $cell);
        }
        // Money: numeric cells, USD format; blank stays an empty cell; explicit zero is a real 0.
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('G6')->getDataType());
        $this->assertSame(1000.5, (float) $sheet->getCell('G6')->getValue());
        $this->assertSame(0.0, (float) $sheet->getCell('H6')->getValue());
        $this->assertNotNull($sheet->getCell('H6')->getValue());
        $this->assertNull($sheet->getCell('G7')->getValue());
        $this->assertNull($sheet->getCell('I7')->getValue());
        $this->assertStringContainsString('$', $sheet->getStyle('G6')->getNumberFormat()->getFormatCode());
        // Formulas preserve the blank-salary rule; computed values agree with the server.
        $this->assertSame('=IF(G6="","",G6-H6+I6)', $sheet->getCell('J6')->getValue());
        $this->assertSame(1025.5, (float) $sheet->getCell('J6')->getCalculatedValue());
        $this->assertSame('', (string) $sheet->getCell('J7')->getCalculatedValue(), 'blank salary => blank payable, even with a deduction');
        $this->assertSame('=SUM(G6:G7)', $sheet->getCell('G8')->getValue());
        $this->assertSame('=SUM(J6:J7)', $sheet->getCell('J8')->getValue());
        $this->assertSame(1025.5, (float) $sheet->getCell('J8')->getCalculatedValue());
    }

    public function test_exports_use_the_current_filters_order_and_label_the_scope(): void
    {
        $this->actingAsUser(self::OWNER);
        [, $two] = $this->seedSheet();
        $sheet = $this->loadXlsx($this->getJson(self::API.'/payroll/export/xlsx?body_id='.$two['id'].'&sort=full_name&direction=desc'));
        $this->assertStringContainsString('الصفوف المطابقة للمرشحات فقط (3 موظفًا)', $sheet->getCell('A2')->getValue());
        $this->assertStringContainsString('الهيئة: هيئة الإدارة', $sheet->getCell('A2')->getValue());
        $this->assertStringContainsString('الاسم الكامل (تنازلي)', $sheet->getCell('A4')->getValue());
        $this->assertSame(['010', '002', '011'], [$sheet->getCell('A6')->getValue(), $sheet->getCell('A7')->getValue(), $sheet->getCell('A8')->getValue()]);
        $this->assertSame('حلب', $sheet->getCell('E6')->getValue());
        $this->getJson(self::API.'/payroll/export/xlsx?sort=nope')->assertUnprocessable();

        // Empty result: still a valid workbook with zero totals.
        $empty = $this->loadXlsx($this->getJson(self::API.'/payroll/export/xlsx?search=zzzz'));
        $this->assertSame('الإجمالي (0 موظفًا)', $empty->getCell('A6')->getValue());
    }

    public function test_pdf_export_is_a_landscape_a3_multi_page_document_with_embedded_font(): void
    {
        $this->actingAsUser(self::OWNER);
        $body = $this->body('هيئة التدريس');
        $changes = [];
        for ($i = 1; $i <= 60; $i++) {
            $row = $this->employee(['employee_number' => sprintf('%04d', $i), 'full_name' => "موظف تجريبي رقم {$i} بن عبد الله الطويل الاسم جدًا لاختبار التفاف الأسطر", 'job_title' => 'صفة وظيفية طويلة للاختبار'], $body);
            $changes[] = $this->change($row, ['fixed_salary' => (string) (1000 + $i), 'deduction' => $i % 2 ? '10' : null]);
        }
        $this->amounts($changes)->assertOk();

        $response = $this->get(self::API.'/payroll/export/pdf');
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1, preg_match_all('#/Type\s*/Page[^s]#', $pdf), 'rows spill to several pages');
        $this->assertMatchesRegularExpression('#/MediaBox\s*\[0(\.0+)?\s+0(\.0+)?\s+(1190|1191)\.\d+\s+(841|842)\.\d+\]#', $pdf, 'A3 landscape');
        $this->assertStringContainsString('/FontFile2', $pdf, 'TrueType font program embedded');
        $this->assertStringContainsString('Cairo', $pdf);
    }
}
