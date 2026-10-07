<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\OwnerPortal;
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
/** Shared fixture: isolated SQLite schema, synthetic users/roles, owner provisioning, payroll migrations. */
abstract class OwnerPayrollTestCase extends TestCase
{
    protected const API = '/api/v1/owner';

    protected const ADMIN = 1;

    protected const OWNER = 2;

    protected const PRESIDENT = 3;

    protected const HR = 4;

    protected const TECH = 5;

    protected const VP_SCIENTIFIC = 6;

    protected const VP_ADMIN = 7;

    protected const OWNER_DISABLED = 8;

    protected const PLAIN = 9;

    protected const ROGUE = 10;

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
        (require base_path('database/migrations/2026_10_09_000000_add_payroll_columns_settings_and_syp_template.php'))->up();

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

    protected function actingAsUser(int $id): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::findOrFail($id));
    }

    protected function body(string $name = 'هيئة اختبار أ'): array
    {
        return $this->postJson(self::API.'/payroll/bodies', ['name' => $name])->assertCreated()->json('data');
    }

    protected function employee(array $overrides = [], ?array $body = null): array
    {
        $body ??= DB::table('payroll_bodies')->first() ? (array) DB::table('payroll_bodies')->first() : $this->body();

        return $this->postJson(self::API.'/payroll/employees', $overrides + [
            'employee_number' => '00123', 'full_name' => 'موظف تجريبي', 'job_title' => 'محاسب', 'body_id' => $body['id'],
            'workplace' => 'afrin', 'academic_level' => null,
        ])->assertCreated()->json('data');
    }

    protected function values(array $changes, ?int $configRevision = null)
    {
        return $this->patchJson(self::API.'/payroll/values', ['changes' => $changes, 'config_revision' => $configRevision ?? $this->configRevision()]);
    }

    protected function configRevision(): int
    {
        return (int) DB::table('payroll_config')->where('id', 1)->value('revision');
    }

    /** Sheet row by employee number. */
    protected function sheetRow(string $number, string $query = ''): array
    {
        return collect($this->getJson(self::API.'/payroll/sheet'.$query)->assertOk()->json('data'))->firstWhere('employee_number', $number);
    }

    /** One change entry: values keyed by column key, revision from the row's current entry revision. */
    protected function change(array $row, array $values): array
    {
        return ['employee_id' => $row['id'], 'expected_revision' => $row['entry_revision'], 'values' => $values];
    }
}
