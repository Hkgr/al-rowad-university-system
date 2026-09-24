<?php

namespace Tests\Feature;

use App\Models\FacultyMember;
use App\Models\User;
use App\Services\DataScopeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Isolated SQLite HTTP and persistence checks; no production migrations run. */
final class AdministrativePersonnelTest extends TestCase
{
    private const URL = '/api/v1/vice-presidency/administrative/personnel';

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'sqlite') {
            throw new \RuntimeException('Use the isolated SQLite test connection.');
        }
        Schema::dropAllTables();
        $this->fixture();
    }

    private function as(int $id): void { Sanctum::actingAs(User::query()->findOrFail($id)); }

    public function test_staff_creation_and_college_transfer_change_dean_visibility_without_materializing_a_course_assignment(): void
    {
        $this->as(1);
        $this->postJson(self::URL.'/faculty', [
            'college_id' => 10, 'employee_number' => 'FAC-100', 'first_name' => 'أحمد',
            'last_name' => 'المدرس', 'academic_rank' => 'مدرس',
        ])->assertCreated()->assertJsonPath('data.college_ids.0', 10);
        $faculty = FacultyMember::query()->firstOrFail();
        self::assertSame(1, FacultyMember::query()->count());
        self::assertTrue(app(DataScopeService::class)->canAccessFacultyMember(User::findOrFail(2), $faculty));
        self::assertFalse(app(DataScopeService::class)->canAccessFacultyMember(User::findOrFail(3), $faculty));

        $this->putJson(self::URL.'/faculty/'.$faculty->faculty_member_id, [
            'college_id' => 11, 'academic_rank' => 'أستاذ مساعد', 'first_name' => 'محمد',
        ])->assertOk()->assertJsonPath('data.college_ids.0', 11)->assertJsonPath('data.first_name', 'محمد');
        self::assertFalse(app(DataScopeService::class)->canAccessFacultyMember(User::findOrFail(2), $faculty));
        self::assertTrue(app(DataScopeService::class)->canAccessFacultyMember(User::findOrFail(3), $faculty));
        $this->postJson(self::URL.'/faculty', [
            'college_id' => 10, 'employee_number' => 'FAC-101', 'first_name' => 'سارة',
            'last_name' => 'المحاضرة',
        ])->assertCreated();
        $this->getJson(self::URL.'/faculty?college_id=11')->assertOk()
            ->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.employee_number', 'FAC-100');
        $this->getJson(self::URL.'/faculty?college_id=10')->assertOk()
            ->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.employee_number', 'FAC-101');
        $this->getJson(self::URL.'/faculty?search=FAC-101')->assertOk()
            ->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.employee_number', 'FAC-101');
        self::assertSame(0, DB::table('course_offering_instructors')->count());
    }

    public function test_narrow_dean_creation_hashes_password_and_links_exact_college(): void
    {
        $this->as(1);
        $response = $this->postJson(self::URL.'/deans', [
            'college_id' => 10, 'employee_number' => 'DEAN-100', 'first_name' => 'عميد',
            'last_name' => 'أول', 'username' => 'dean.new', 'email' => 'dean.new@example.test',
            'password' => 'LongSecret-2026!',
        ])->assertCreated()->assertJsonPath('data.college_id', 10);
        self::assertStringNotContainsString('LongSecret-2026!', $response->getContent());
        $dean = User::query()->where('username', 'dean.new')->firstOrFail();
        self::assertTrue(Hash::check('LongSecret-2026!', $dean->password_hash));
        self::assertTrue($dean->isDean());
        self::assertSame(10, (int) $dean->accessScopes()->where('is_active', true)->value('scope_id'));
        self::assertSame(1, $dean->accessScopes()->where('is_active', true)->count());
        $this->postJson(self::URL.'/deans', [
            'college_id' => 10, 'employee_number' => 'DEAN-101', 'first_name' => 'عميد',
            'last_name' => 'ثان', 'username' => 'dean.second', 'email' => 'second@example.test',
            'password' => 'LongSecret-2026!',
        ])->assertUnprocessable();
        self::assertSame(1, DB::table('employees')->where('employee_number', 'DEAN-100')->count());
        self::assertSame(0, DB::table('employees')->where('employee_number', 'DEAN-101')->count());

        $this->postJson(self::URL.'/deans/'.$dean->user_id.'/colleges/10/retire')->assertOk();
        self::assertFalse($dean->fresh()->isDean());
        self::assertSame(0, $dean->accessScopes()->where('is_active', true)->count());
        self::assertSame(1, DB::table('users')->where('user_id', $dean->user_id)->count());
    }

    public function test_role_scope_and_individual_permissions_are_required_by_the_server(): void
    {
        $this->as(4); // Super Admin bypass must never open this delegated endpoint.
        $this->postJson(self::URL.'/deans', [])->assertForbidden();
        $this->as(5); // VP role + permission without actual university scope.
        $this->getJson(self::URL.'/deans')->assertForbidden();
        $this->as(1);
        DB::table('role_permissions')->where('permission_id', 4)->delete(); // dean manage
        $this->getJson(self::URL.'/deans')->assertOk();
        $this->postJson(self::URL.'/deans', [])->assertForbidden();
        self::assertSame(0, DB::table('user_activity_logs')->count());
    }

    public function test_existing_clean_account_can_be_linked_but_privileged_account_cannot_be_repurposed(): void
    {
        DB::table('employees')->insert(['employee_id' => 15, 'employee_number' => 'EMP-15',
            'first_name' => 'ليلى', 'last_name' => 'العميد', 'employee_type_id' => 2,
            'employee_status_id' => 1, 'organizational_unit_id' => 11]);
        DB::table('users')->insert(['user_id' => 15, 'username' => 'dean.existing',
            'email' => 'existing@example.test', 'password_hash' => Hash::make('Original-2026!'),
            'account_status_id' => 1, 'employee_id' => 15]);
        $this->as(1);
        $this->postJson(self::URL.'/deans', [
            'college_id' => 11, 'employee_id' => 15, 'user_id' => 15,
        ])->assertCreated()->assertJsonPath('data.user_id', 15);
        self::assertTrue(User::findOrFail(15)->isDean());
        self::assertSame(11, (int) DB::table('user_access_scopes')->where('user_id', 15)->value('scope_id'));
        $this->postJson(self::URL.'/deans', [
            'college_id' => 10, 'employee_id' => 15, 'user_id' => 15,
        ])->assertUnprocessable();
        $this->postJson(self::URL.'/deans', [
            'college_id' => 10, 'employee_id' => 15, 'user_id' => 4,
        ])->assertUnprocessable();
    }

    private function fixture(): void
    {
        Schema::create('organizational_units', function (Blueprint $t): void {
            $t->increments('organizational_unit_id'); $t->string('unit_code'); $t->string('unit_name'); $t->boolean('is_active')->default(1);
        });
        Schema::create('colleges', function (Blueprint $t): void {
            $t->increments('college_id'); $t->integer('organizational_unit_id'); $t->string('college_code');
            $t->string('college_name'); $t->boolean('is_active')->default(1); $t->timestamps();
        });
        Schema::create('employee_types', function (Blueprint $t): void {
            $t->increments('employee_type_id'); $t->string('type_code'); $t->boolean('is_active')->default(1);
        });
        Schema::create('employee_statuses', function (Blueprint $t): void {
            $t->increments('employee_status_id'); $t->string('status_code'); $t->boolean('is_active')->default(1);
        });
        Schema::create('account_statuses', function (Blueprint $t): void {
            $t->increments('account_status_id'); $t->string('status_code'); $t->boolean('is_active')->default(1);
        });
        Schema::create('employees', function (Blueprint $t): void {
            $t->increments('employee_id'); $t->string('employee_number')->unique(); $t->string('first_name');
            $t->string('last_name'); $t->string('email')->nullable(); $t->integer('employee_type_id');
            $t->integer('employee_status_id'); $t->integer('organizational_unit_id')->nullable(); $t->timestamps();
        });
        Schema::create('employee_unit_assignments', function (Blueprint $t): void {
            $t->increments('assignment_id'); $t->integer('employee_id'); $t->integer('organizational_unit_id');
            $t->date('start_date'); $t->date('end_date')->nullable(); $t->boolean('is_active'); $t->timestamps();
        });
        Schema::create('faculty_members', function (Blueprint $t): void {
            $t->increments('faculty_member_id'); $t->integer('employee_id')->unique(); $t->string('academic_rank')->nullable();
            $t->string('specialization')->nullable(); $t->string('office_location')->nullable();
            $t->boolean('is_active'); $t->timestamps();
        });
        Schema::create('course_offering_instructors', function (Blueprint $t): void {
            $t->increments('course_offering_instructor_id'); $t->integer('faculty_member_id');
        });
        Schema::create('roles', function (Blueprint $t): void {
            $t->increments('role_id'); $t->string('role_code'); $t->boolean('is_active');
        });
        Schema::create('permissions', function (Blueprint $t): void {
            $t->increments('permission_id'); $t->string('permission_code'); $t->boolean('is_active');
        });
        Schema::create('role_permissions', function (Blueprint $t): void {
            $t->increments('role_permission_id'); $t->integer('role_id'); $t->integer('permission_id');
        });
        Schema::create('users', function (Blueprint $t): void {
            $t->increments('user_id'); $t->string('username')->unique(); $t->string('email')->unique();
            $t->string('password_hash'); $t->integer('account_status_id'); $t->integer('employee_id')->nullable();
            $t->integer('created_by_user_id')->nullable(); $t->integer('failed_login_attempts')->default(0); $t->timestamps();
        });
        Schema::create('user_roles', function (Blueprint $t): void {
            $t->increments('user_role_id'); $t->integer('user_id'); $t->integer('role_id');
            $t->integer('assigned_by_user_id')->nullable(); $t->timestamp('assigned_at')->nullable();
            $t->boolean('is_active');
        });
        Schema::create('user_access_scopes', function (Blueprint $t): void {
            $t->increments('user_access_scope_id'); $t->integer('user_id'); $t->string('scope_type');
            $t->integer('scope_id'); $t->boolean('is_active'); $t->timestamps();
        });
        Schema::create('user_activity_logs', function (Blueprint $t): void {
            $t->increments('activity_log_id'); $t->integer('user_id'); $t->string('module_code');
            $t->string('action_code'); $t->text('description')->nullable(); $t->string('ip_address')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('personal_access_tokens', function (Blueprint $t): void {
            $t->id(); $t->morphs('tokenable'); $t->string('name'); $t->string('token', 64)->unique();
            $t->text('abilities')->nullable(); $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable(); $t->timestamps();
        });

        DB::table('organizational_units')->insert([
            ['organizational_unit_id' => 1, 'unit_code' => 'PRES', 'unit_name' => 'University'],
            ['organizational_unit_id' => 10, 'unit_code' => 'C10', 'unit_name' => 'College 10'],
            ['organizational_unit_id' => 11, 'unit_code' => 'C11', 'unit_name' => 'College 11'],
        ]);
        DB::table('colleges')->insert([
            ['college_id' => 10, 'organizational_unit_id' => 10, 'college_code' => 'C10', 'college_name' => 'College 10'],
            ['college_id' => 11, 'organizational_unit_id' => 11, 'college_code' => 'C11', 'college_name' => 'College 11'],
        ]);
        DB::table('employee_types')->insert([['employee_type_id' => 1, 'type_code' => 'academic'], ['employee_type_id' => 2, 'type_code' => 'administrative']]);
        DB::table('employee_statuses')->insert(['employee_status_id' => 1, 'status_code' => 'active']);
        DB::table('account_statuses')->insert(['account_status_id' => 1, 'status_code' => 'active']);
        DB::table('roles')->insert([['role_id' => 1, 'role_code' => 'vice_president_administrative', 'is_active' => 1],
            ['role_id' => 2, 'role_code' => 'dean', 'is_active' => 1], ['role_id' => 3, 'role_code' => 'super_admin', 'is_active' => 1]]);
        foreach (['administrative_staff.view', 'administrative_staff.manage', 'administrative_deans.view', 'administrative_deans.manage'] as $i => $permission) {
            DB::table('permissions')->insert(['permission_id' => $i + 1, 'permission_code' => $permission, 'is_active' => 1]);
            DB::table('role_permissions')->insert(['role_id' => 1, 'permission_id' => $i + 1]);
        }
        for ($i = 1; $i <= 5; $i++) {
            DB::table('users')->insert(['user_id' => $i, 'username' => 'user'.$i, 'email' => 'user'.$i.'@example.test',
                'password_hash' => Hash::make('Existing-Secret-2026'), 'account_status_id' => 1]);
            DB::table('user_roles')->insert(['user_id' => $i, 'role_id' => match ($i) { 2, 3 => 2, 4 => 3, default => 1 }, 'is_active' => 1]);
            DB::table('user_access_scopes')->insert(['user_id' => $i, 'scope_type' => match ($i) { 2, 3 => 'college', default => 'university' },
                'scope_id' => match ($i) { 2 => 10, 3 => 11, default => 1 }, 'is_active' => 1]);
        }
        DB::table('user_access_scopes')->where('user_id', 5)->update(['is_active' => 0]);
    }
}
