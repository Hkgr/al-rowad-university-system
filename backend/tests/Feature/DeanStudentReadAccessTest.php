<?php

namespace Tests\Feature;

use App\Models\AcademicProgram;
use App\Models\AcademicLevel;
use App\Models\AccountStatus;
use App\Models\College;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Student;
use App\Models\StudentStatus;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeanStudentReadAccessTest extends TestCase
{
    private User $dean;
    private Student $collegeAStudent;
    private Student $collegeBStudent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->createIsolatedSchema();

        $academicLevel = AcademicLevel::query()->create([
            'level_code' => 'L1',
            'level_name' => 'First',
            'level_order' => 1,
            'is_active' => true,
        ]);
        $studentStatus = StudentStatus::query()->create([
            'status_code' => 'active',
            'status_name' => 'Active',
            'is_active' => true,
        ]);

        $collegeA = College::query()->create([
            'college_code' => 'COL-A',
            'college_name' => 'College A',
            'is_active' => true,
        ]);
        $collegeB = College::query()->create([
            'college_code' => 'COL-B',
            'college_name' => 'College B',
            'is_active' => true,
        ]);

        $departmentA = Department::query()->create([
            'college_id' => $collegeA->college_id,
            'department_code' => 'DEP-A',
            'department_name' => 'Department A',
            'is_active' => true,
        ]);
        $departmentB = Department::query()->create([
            'college_id' => $collegeB->college_id,
            'department_code' => 'DEP-B',
            'department_name' => 'Department B',
            'is_active' => true,
        ]);

        $programA = AcademicProgram::query()->create([
            'department_id' => $departmentA->department_id,
            'program_code' => 'PROG-A',
            'program_name' => 'Program A',
            'is_active' => true,
        ]);
        $programB = AcademicProgram::query()->create([
            'department_id' => $departmentB->department_id,
            'program_code' => 'PROG-B',
            'program_name' => 'Program B',
            'is_active' => true,
        ]);

        $this->collegeAStudent = $this->createStudent('A-001', $programA, $academicLevel, $studentStatus);
        $this->collegeBStudent = $this->createStudent('B-001', $programB, $academicLevel, $studentStatus);
        $this->dean = $this->createUserWithRole('dean', [
            'students.view',
            'academic_structure.view',
        ]);
        $this->dean->accessScopes()->create([
            'scope_type' => 'college',
            'scope_id' => $collegeA->college_id,
            'is_active' => true,
        ]);
    }

    public function test_dean_list_is_limited_to_students_in_the_scoped_college(): void
    {
        $this->actingAs($this->dean, 'sanctum')
            ->getJson('/api/v1/students')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.student_number', 'A-001')
            ->assertJsonMissing(['student_number' => 'B-001']);
    }

    public function test_dean_can_view_an_in_scope_profile_but_gets_403_outside_the_college(): void
    {
        $this->actingAs($this->dean, 'sanctum')
            ->getJson('/api/v1/students/'.$this->collegeAStudent->student_id.'/profile')
            ->assertOk()
            ->assertJsonPath('data.student_number', 'A-001');

        $this->actingAs($this->dean, 'sanctum')
            ->getJson('/api/v1/students/'.$this->collegeBStudent->student_id.'/profile')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'forbidden');
    }

    public function test_student_page_structure_lookups_are_limited_to_the_scoped_college(): void
    {
        $this->actingAs($this->dean, 'sanctum')
            ->getJson('/api/v1/colleges')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.college_code', 'COL-A');

        $this->actingAs($this->dean, 'sanctum')
            ->getJson('/api/v1/departments')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.department_code', 'DEP-A');

        $this->actingAs($this->dean, 'sanctum')
            ->getJson('/api/v1/academic-programs')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.program_code', 'PROG-A');
    }

    public function test_read_only_dean_cannot_update_archive_restore_or_force_delete_students(): void
    {
        $studentId = $this->collegeAStudent->student_id;

        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/students', [])
            ->assertForbidden();

        $this->actingAs($this->dean, 'sanctum')
            ->putJson('/api/v1/students/'.$studentId, ['first_name' => 'Changed'])
            ->assertForbidden();
        $this->assertSame('Test', $this->collegeAStudent->fresh()->first_name);

        $this->actingAs($this->dean, 'sanctum')
            ->deleteJson('/api/v1/students/'.$studentId)
            ->assertForbidden();
        $this->assertNull($this->collegeAStudent->fresh()->deleted_at);

        $this->collegeAStudent->delete();

        $this->actingAs($this->dean, 'sanctum')
            ->postJson('/api/v1/students/'.$studentId.'/restore')
            ->assertForbidden();

        $this->actingAs($this->dean, 'sanctum')
            ->deleteJson('/api/v1/students/'.$studentId.'/force')
            ->assertForbidden();

        $this->assertSoftDeleted('students', ['student_id' => $studentId]);
    }

    public function test_super_admin_scope_bypass_is_unchanged(): void
    {
        $superAdmin = $this->createUserWithRole('super_admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/students')
            ->assertOk()
            ->assertJsonCount(2, 'data.data');

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/students/'.$this->collegeBStudent->student_id.'/profile')
            ->assertOk()
            ->assertJsonPath('data.student_number', 'B-001');
    }

    private function createStudent(
        string $studentNumber,
        AcademicProgram $program,
        AcademicLevel $academicLevel,
        StudentStatus $studentStatus
    ): Student
    {
        return Student::query()->create([
            'student_number' => $studentNumber,
            'first_name' => 'Test',
            'last_name' => $studentNumber,
            'academic_program_id' => $program->academic_program_id,
            'current_academic_level_id' => $academicLevel->academic_level_id,
            'student_status_id' => $studentStatus->student_status_id,
            'enrollment_date' => '2025-09-01',
        ]);
    }

    private function createUserWithRole(string $roleCode, array $permissionCodes = []): User
    {
        $active = AccountStatus::query()->firstOrCreate(
            ['status_code' => 'active'],
            ['status_name' => 'Active', 'is_active' => true]
        );
        $role = Role::query()->create([
            'role_code' => $roleCode,
            'role_name' => $roleCode,
            'is_system_role' => true,
            'is_active' => true,
        ]);
        $user = User::query()->create([
            'username' => $roleCode,
            'email' => $roleCode.'@test.invalid',
            'password_hash' => 'unused',
            'account_status_id' => $active->account_status_id,
        ]);
        UserRole::query()->create([
            'user_id' => $user->user_id,
            'role_id' => $role->role_id,
            'is_active' => true,
        ]);

        foreach ($permissionCodes as $permissionCode) {
            $permission = Permission::query()->create([
                'permission_code' => $permissionCode,
                'permission_name' => $permissionCode,
                'is_active' => true,
            ]);
            RolePermission::query()->create([
                'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id,
            ]);
        }

        return $user;
    }

    private function createIsolatedSchema(): void
    {
        Schema::create('account_statuses', function (Blueprint $table): void {
            $table->id('account_status_id');
            $table->string('status_code')->unique();
            $table->string('status_name');
            $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id('role_id');
            $table->string('role_code')->unique();
            $table->string('role_name');
            $table->boolean('is_system_role');
            $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id('permission_id');
            $table->unsignedBigInteger('module_id')->nullable();
            $table->string('permission_code')->unique();
            $table->string('permission_name');
            $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->id('role_permission_id');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->timestamp('granted_at')->useCurrent();
            $table->unique(['role_id', 'permission_id']);
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id('user_id');
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->unsignedBigInteger('account_status_id');
            $table->unsignedBigInteger('student_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->timestamps();
        });
        Schema::create('user_roles', function (Blueprint $table): void {
            $table->id('user_role_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('assigned_by_user_id')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->boolean('is_active');
        });
        Schema::create('colleges', function (Blueprint $table): void {
            $table->id('college_id');
            $table->unsignedBigInteger('organizational_unit_id')->nullable();
            $table->string('college_code')->unique();
            $table->string('college_name');
            $table->text('description')->nullable();
            $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::create('departments', function (Blueprint $table): void {
            $table->id('department_id');
            $table->unsignedBigInteger('college_id');
            $table->unsignedBigInteger('organizational_unit_id')->nullable();
            $table->string('department_code')->unique();
            $table->string('department_name');
            $table->text('description')->nullable();
            $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::create('academic_programs', function (Blueprint $table): void {
            $table->id('academic_program_id');
            $table->unsignedBigInteger('department_id');
            $table->string('program_code')->unique();
            $table->string('program_name');
            $table->string('degree_level')->nullable();
            $table->integer('total_credit_hours')->nullable();
            $table->integer('duration_years')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::create('academic_levels', function (Blueprint $table): void {
            $table->id('academic_level_id');
            $table->string('level_code')->unique();
            $table->string('level_name');
            $table->integer('level_order');
            $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::create('student_statuses', function (Blueprint $table): void {
            $table->id('student_status_id');
            $table->string('status_code')->unique();
            $table->string('status_name');
            $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::create('students', function (Blueprint $table): void {
            $table->id('student_id');
            $table->string('student_number')->unique();
            $table->unsignedBigInteger('admission_application_id')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('father_name')->nullable();
            $table->string('mother_name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('nationality')->nullable();
            $table->unsignedBigInteger('academic_program_id')->nullable();
            $table->unsignedBigInteger('current_academic_level_id');
            $table->date('enrollment_date');
            $table->unsignedBigInteger('student_status_id');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('student_course_registrations', function (Blueprint $table): void {
            $table->id('student_course_registration_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('course_offering_id');
            $table->timestamps();
        });
        Schema::create('user_access_scopes', function (Blueprint $table): void {
            $table->id('user_access_scope_id');
            $table->unsignedBigInteger('user_id');
            $table->string('scope_type');
            $table->unsignedBigInteger('scope_id');
            $table->boolean('is_active');
            $table->timestamps();
            $table->unique(['user_id', 'scope_type', 'scope_id']);
        });
    }
}
