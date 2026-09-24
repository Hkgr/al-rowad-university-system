<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite copy of the production tables used by the administrative VP portal
 * (columns, nullability, defaults and unique keys generated from the MariaDB
 * schema of `alrowad_uni_rust`; foreign keys and generated columns omitted).
 */
trait AdministrativeGovernanceSchema
{
    protected function createAdministrativeGovernanceSchema(): void
    {
        Schema::create('account_statuses', function (Blueprint $t): void {
            $t->increments('account_status_id');
            $t->string('status_code', 50);
            $t->string('status_name', 100);
            $t->string('description', 255)->nullable();
            $t->boolean('is_active')->default(1);
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();
            $t->unique(['status_code'], 'account_statuses_status_code');
        });
        Schema::create('users', function (Blueprint $t): void {
            $t->increments('user_id');
            $t->string('username', 80);
            $t->string('email', 150);
            $t->string('password_hash', 255);
            $t->integer('account_status_id');
            $t->integer('student_id')->nullable();
            $t->integer('employee_id')->nullable();
            $t->integer('board_member_id')->nullable();
            $t->dateTime('last_login_at')->nullable();
            $t->dateTime('email_verified_at')->nullable();
            $t->integer('failed_login_attempts')->default(0);
            $t->integer('created_by_user_id')->nullable();
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();
            $t->unique(['email'], 'users_email');
            $t->unique(['employee_id'], 'users_uq_users_employee_identity');
            $t->unique(['student_id'], 'users_uq_users_student_identity');
            $t->unique(['username'], 'users_username');
        });
        Schema::create('roles', function (Blueprint $t): void {
            $t->increments('role_id');
            $t->string('role_code', 80);
            $t->string('role_name', 150);
            $t->string('description', 255)->nullable();
            $t->boolean('is_system_role')->default(0);
            $t->boolean('is_active')->default(1);
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();
            $t->unique(['role_code'], 'roles_role_code');
        });
        Schema::create('permissions', function (Blueprint $t): void {
            $t->increments('permission_id');
            $t->integer('module_id');
            $t->string('permission_code', 120);
            $t->string('permission_name', 150);
            $t->string('description', 255)->nullable();
            $t->boolean('is_active')->default(1);
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();
            $t->unique(['permission_code'], 'permissions_permission_code');
        });
        Schema::create('user_roles', function (Blueprint $t): void {
            $t->increments('user_role_id');
            $t->integer('user_id');
            $t->integer('role_id');
            $t->integer('assigned_by_user_id')->nullable();
            $t->dateTime('assigned_at')->useCurrent();
            $t->boolean('is_active')->default(1);
            $t->unique(['user_id', 'role_id'], 'user_roles_uq_user_role');
        });
        Schema::create('role_permissions', function (Blueprint $t): void {
            $t->increments('role_permission_id');
            $t->integer('role_id');
            $t->integer('permission_id');
            $t->dateTime('granted_at')->useCurrent();
            $t->unique(['role_id', 'permission_id'], 'role_permissions_uq_role_permission');
        });
        Schema::create('system_modules', function (Blueprint $t): void {
            $t->increments('module_id');
            $t->string('module_code', 80);
            $t->string('module_name', 150);
            $t->string('description', 255)->nullable();
            $t->boolean('is_active')->default(1);
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();
            $t->unique(['module_code'], 'system_modules_module_code');
        });
        Schema::create('user_access_scopes', function (Blueprint $t): void {
            $t->increments('user_access_scope_id');
            $t->integer('user_id');
            $t->string('scope_type', 50);
            $t->integer('scope_id');
            $t->boolean('is_active')->default(1);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique(['user_id', 'scope_type', 'scope_id'], 'user_access_scopes_user_scope_unique');
        });
        Schema::create('user_activity_logs', function (Blueprint $t): void {
            $t->increments('activity_log_id');
            $t->integer('user_id');
            $t->string('module_code', 80)->nullable();
            $t->string('action_code', 120)->nullable();
            $t->text('description')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->dateTime('created_at')->useCurrent();
        });
        Schema::create('organizational_units', function (Blueprint $t): void {
            $t->increments('organizational_unit_id');
            $t->string('unit_code', 50)->nullable();
            $t->string('unit_name', 200);
            $t->integer('unit_type_id');
            $t->integer('parent_unit_id')->nullable();
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(1);
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();
            $t->unique(['unit_code'], 'organizational_units_unit_code');
        });
        Schema::create('colleges', function (Blueprint $t): void {
            $t->increments('college_id');
            $t->integer('organizational_unit_id')->nullable();
            $t->string('college_code', 50);
            $t->string('college_name', 200);
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(1);
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();
            $t->unique(['college_code'], 'colleges_college_code');
            $t->unique(['organizational_unit_id'], 'colleges_organizational_unit_id');
        });
        Schema::create('departments', function (Blueprint $t): void {
            $t->increments('department_id');
            $t->integer('college_id');
            $t->integer('organizational_unit_id')->nullable();
            $t->string('department_code', 50);
            $t->string('department_name', 200);
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(1);
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();
            $t->unique(['department_code'], 'departments_department_code');
            $t->unique(['organizational_unit_id'], 'departments_organizational_unit_id');
        });
        Schema::create('academic_programs', function (Blueprint $t): void {
            $t->increments('academic_program_id');
            $t->integer('department_id');
            $t->string('program_code', 50);
            $t->string('program_name', 200);
            $t->string('degree_level', 80)->default('Bachelor');
            $t->integer('total_credit_hours')->default(120);
            $t->integer('duration_years')->default(4);
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(1);
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();
            $t->string('plan_state', 20)->default('legacy');
            $t->integer('default_academic_plan_version_id')->nullable();
            $t->dateTime('archived_at')->nullable();
            $t->unique(['program_code'], 'academic_programs_program_code');
        });
        Schema::create('academic_levels', function (Blueprint $t): void {
            $t->increments('academic_level_id');
            $t->string('level_code', 50);
            $t->string('level_name', 100);
            $t->integer('level_order');
            $t->boolean('is_active')->default(1);
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();
            $t->unique(['level_code'], 'academic_levels_level_code');
        });
        Schema::create('academic_years', function (Blueprint $t): void {
            $t->increments('academic_year_id');
            $t->string('year_name', 50);
            $t->date('start_date');
            $t->date('end_date');
            $t->boolean('is_current')->default(0);
            $t->boolean('is_active')->default(1);
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();
            $t->string('calendar_lifecycle_status', 16)->default('draft');
            $t->integer('calendar_active_slot')->nullable();
            $t->unique(['year_name'], 'academic_years_year_name');
        });
        Schema::create('semesters', function (Blueprint $t): void {
            $t->increments('semester_id');
            $t->string('semester_code', 50);
            $t->string('semester_name', 100);
            $t->integer('semester_order');
            $t->boolean('is_active')->default(1);
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();
            $t->unique(['semester_code'], 'semesters_semester_code');
        });
        Schema::create('courses', function (Blueprint $t): void {
            $t->increments('course_id');
            $t->string('course_code', 50);
            $t->string('course_name', 200);
            $t->integer('credit_hours');
            $t->integer('theoretical_hours')->nullable()->default(0);
            $t->integer('practical_hours')->nullable()->default(0);
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(1);
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();
            $t->unique(['course_code'], 'courses_course_code');
        });
        Schema::create('course_offerings', function (Blueprint $t): void {
            $t->increments('course_offering_id');
            $t->integer('course_id');
            $t->integer('academic_year_id');
            $t->integer('semester_id');
            $t->integer('department_id')->nullable();
            $t->integer('academic_program_id')->nullable();
            $t->integer('faculty_member_id')->nullable();
            $t->integer('capacity')->default(0);
            $t->integer('available_seats')->default(0);
            $t->string('status', 50)->default('open');
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();
            $t->unique(['course_id', 'academic_program_id', 'academic_year_id', 'semester_id'], 'course_offerings_uq_course_offering_program_term');
        });
        Schema::create('course_offering_instructors', function (Blueprint $t): void {
            $t->increments('course_offering_instructor_id');
            $t->integer('course_offering_id');
            $t->integer('faculty_member_id');
            $t->string('instructor_role', 50)->default('theoretical');
            $t->boolean('is_primary')->default(0);
            $t->boolean('is_active')->default(1);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique(['course_offering_id', 'instructor_role'], 'course_offering_instructors_uq_course_offering_role');
        });
        Schema::create('course_instructors', function (Blueprint $t): void {
            $t->increments('course_instructor_id');
            $t->integer('course_id');
            $t->integer('faculty_member_id');
            $t->boolean('is_primary')->default(0);
            $t->boolean('is_active')->default(1);
            $t->dateTime('created_at')->useCurrent();
            $t->unique(['course_id', 'faculty_member_id'], 'course_instructors_uq_course_instructor');
        });

        Schema::create('employee_statuses', function (Blueprint $t): void {
            $t->increments('employee_status_id');
            $t->string('status_code', 50);
            $t->string('status_name', 100);
            $t->string('description', 255)->nullable();
            $t->boolean('is_active')->default(1);
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();
            $t->unique(['status_code'], 'employee_statuses_status_code');
        });
        Schema::create('employee_types', function (Blueprint $t): void {
            $t->increments('employee_type_id');
            $t->string('type_code', 50);
            $t->string('type_name', 100);
            $t->string('description', 255)->nullable();
            $t->boolean('is_active')->default(1);
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();
            $t->unique(['type_code'], 'employee_types_type_code');
        });
        Schema::create('employees', function (Blueprint $t): void {
            $t->increments('employee_id');
            $t->string('employee_number', 50);
            $t->string('first_name', 100);
            $t->string('last_name', 100);
            $t->string('father_name', 100)->nullable();
            $t->string('mother_name', 100)->nullable();
            $t->string('phone_number', 30)->nullable();
            $t->string('email', 150)->nullable();
            $t->date('hire_date')->nullable();
            $t->integer('employee_type_id');
            $t->integer('employee_status_id');
            $t->integer('organizational_unit_id')->nullable();
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();
            $t->unique(['email'], 'employees_email');
            $t->unique(['employee_number'], 'employees_employee_number');
        });
        Schema::create('employee_unit_assignments', function (Blueprint $t): void {
            $t->increments('assignment_id');
            $t->integer('employee_id');
            $t->integer('organizational_unit_id');
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->string('assignment_notes', 255)->nullable();
            $t->boolean('is_active')->default(1);
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();
        });
        Schema::create('positions', function (Blueprint $t): void {
            $t->increments('position_id');
            $t->string('position_code', 50);
            $t->string('position_title', 150);
            $t->string('description', 255)->nullable();
            $t->boolean('is_active')->default(1);
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();
            $t->unique(['position_code'], 'positions_position_code');
        });
        Schema::create('employee_positions', function (Blueprint $t): void {
            $t->increments('employee_position_id');
            $t->integer('employee_id');
            $t->integer('position_id');
            $t->integer('organizational_unit_id')->nullable();
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->boolean('is_primary')->default(0);
            $t->boolean('is_active')->default(1);
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();
        });
        Schema::create('faculty_members', function (Blueprint $t): void {
            $t->increments('faculty_member_id');
            $t->integer('employee_id');
            $t->string('academic_rank', 100)->nullable();
            $t->string('specialization', 200)->nullable();
            $t->string('office_location', 150)->nullable();
            $t->boolean('is_active')->default(1);
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();
            $t->unique(['employee_id'], 'faculty_members_employee_id');
        });
        Schema::create('student_statuses', function (Blueprint $t): void {
            $t->increments('student_status_id');
            $t->string('status_code', 50);
            $t->string('status_name', 100);
            $t->string('description', 255)->nullable();
            $t->boolean('is_active')->default(1);
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();
            $t->unique(['status_code'], 'student_statuses_status_code');
        });
        Schema::create('students', function (Blueprint $t): void {
            $t->increments('student_id');
            $t->string('student_number', 50);
            $t->integer('admission_application_id')->nullable();
            $t->string('first_name', 100);
            $t->string('last_name', 100);
            $t->string('father_name', 100)->nullable();
            $t->string('mother_name', 100)->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('gender', 20)->nullable();
            $t->string('phone_number', 30)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('address', 255)->nullable();
            $t->string('nationality', 100)->nullable();
            $t->integer('academic_program_id');
            $t->integer('current_academic_level_id');
            $t->date('enrollment_date');
            $t->integer('student_status_id');
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent();
            $t->dateTime('deleted_at')->nullable();
            $t->text('deregistration_reason')->nullable();
            $t->unique(['admission_application_id'], 'students_admission_application_id');
            $t->unique(['email'], 'students_email');
            $t->unique(['student_number'], 'students_student_number');
        });
        Schema::create('teaching_assignment_requests', function (Blueprint $t): void {
            $t->increments('teaching_assignment_request_id');
            $t->integer('course_offering_id');
            $t->integer('faculty_member_id');
            $t->string('instructor_role', 50);
            $t->string('status', 32);
            $t->integer('submission_version')->default(1);
            $t->integer('current_slot')->nullable();
            $t->integer('requested_by_user_id');
            $t->dateTime('submitted_at')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->dateTime('superseded_at')->nullable();
            $t->integer('superseded_by_request_id')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->string('action_type', 16)->default('assign');
            $t->text('action_reason')->nullable();
            $t->integer('target_course_offering_instructor_id')->nullable();
            $t->unique(['course_offering_id', 'instructor_role', 'current_slot'], 'teaching_assignment_requests_uq_tar_current_slot');
        });
        Schema::create('teaching_assignment_reviews', function (Blueprint $t): void {
            $t->increments('teaching_assignment_review_id');
            $t->integer('teaching_assignment_request_id');
            $t->string('review_authority', 50);
            $t->string('status', 32);
            $t->integer('reviewed_by_user_id')->nullable();
            $t->dateTime('reviewed_at')->nullable();
            $t->text('reason')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique(['teaching_assignment_request_id', 'review_authority'], 'teaching_assignment_reviews_uq_tarv_request_authority');
        });
        Schema::create('teaching_assignment_events', function (Blueprint $t): void {
            $t->increments('teaching_assignment_event_id');
            $t->integer('teaching_assignment_request_id');
            $t->string('event_type', 64);
            $t->integer('actor_user_id')->nullable();
            $t->integer('submission_version')->nullable();
            $t->text('notes')->nullable();
            $t->dateTime('created_at')->useCurrent();
        });
    }

    /**
     * Synthetic fixture. Users: 1 super_admin, 2 administrative VP, 3 VP without scope,
     * 5 no permission, 6 dean of college 1, 7 VP + foreign role, 8 dean candidate
     * (doctor_instructor), 9 exam_committee, 10 scientific VP, 11 disabled VP,
     * 12 dean holding a university scope. Colleges 1 (unit 10), 2 (unit 11), 3 (no unit).
     */
    protected function user(int $id, string $username, ?int $employeeId, int $status = 1): void
    {
        DB::table('users')->insert([
            'user_id' => $id, 'username' => $username, 'email' => $username.'@alrowad.test',
            'password_hash' => 'x', 'account_status_id' => $status, 'employee_id' => $employeeId,
        ]);
    }

    protected function employee(int $id, string $number, string $last, ?int $unitId, int $status = 1): void
    {
        DB::table('employees')->insert([
            'employee_id' => $id, 'employee_number' => $number, 'first_name' => 'T', 'last_name' => $last,
            'employee_type_id' => 1, 'employee_status_id' => $status, 'organizational_unit_id' => $unitId,
        ]);
    }

    protected function seedAdministrativeGovernanceFixture(): void
    {
        DB::table('account_statuses')->insert([['account_status_id' => 1, 'status_code' => 'active', 'status_name' => 'active'], ['account_status_id' => 2, 'status_code' => 'disabled', 'status_name' => 'disabled']]);
        DB::table('organizational_units')->insert([
            ['organizational_unit_id' => 1, 'unit_code' => 'PRES', 'unit_name' => 'رئاسة الجامعة', 'unit_type_id' => 1],
            ['organizational_unit_id' => 10, 'unit_code' => 'FA', 'unit_name' => 'كلية أ', 'unit_type_id' => 2],
            ['organizational_unit_id' => 11, 'unit_code' => 'FB', 'unit_name' => 'كلية ب', 'unit_type_id' => 2],
        ]);
        DB::table('colleges')->insert([
            ['college_id' => 1, 'college_code' => 'A', 'college_name' => 'كلية أ', 'organizational_unit_id' => 10, 'is_active' => 1],
            ['college_id' => 2, 'college_code' => 'B', 'college_name' => 'كلية ب', 'organizational_unit_id' => 11, 'is_active' => 1],
            ['college_id' => 3, 'college_code' => 'C', 'college_name' => 'كلية بلا وحدة', 'organizational_unit_id' => null, 'is_active' => 1],
        ]);
        DB::table('departments')->insert([
            ['department_id' => 1, 'college_id' => 1, 'department_code' => 'DA', 'department_name' => 'قسم أ'],
            ['department_id' => 2, 'college_id' => 2, 'department_code' => 'DB', 'department_name' => 'قسم ب'],
        ]);
        DB::table('academic_programs')->insert([
            ['academic_program_id' => 1, 'department_id' => 1, 'program_code' => 'PA', 'program_name' => 'برنامج أ'],
            ['academic_program_id' => 2, 'department_id' => 2, 'program_code' => 'PB', 'program_name' => 'برنامج ب'],
        ]);
        DB::table('academic_levels')->insert(['academic_level_id' => 1, 'level_code' => 'L1', 'level_name' => 'الأولى', 'level_order' => 1]);
        DB::table('academic_years')->insert([
            ['academic_year_id' => 1, 'year_name' => '2025-2026', 'start_date' => '2025-09-01', 'end_date' => '2026-08-31', 'is_current' => 1],
            ['academic_year_id' => 2, 'year_name' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-08-31', 'is_current' => 0],
        ]);
        DB::table('semesters')->insert(['semester_id' => 1, 'semester_code' => 'S1', 'semester_name' => 'الأول', 'semester_order' => 1, 'is_active' => 1]);
        DB::table('courses')->insert([
            ['course_id' => 1, 'course_code' => 'ACC101', 'course_name' => 'محاسبة', 'credit_hours' => 3, 'theoretical_hours' => 2, 'practical_hours' => 2],
            ['course_id' => 2, 'course_code' => 'ENG101', 'course_name' => 'هندسة', 'credit_hours' => 3, 'theoretical_hours' => 2, 'practical_hours' => 0],
            ['course_id' => 3, 'course_code' => 'ACC102', 'course_name' => 'محاسبة 2', 'credit_hours' => 3, 'theoretical_hours' => 2, 'practical_hours' => 0],
        ]);
        DB::table('course_offerings')->insert([
            ['course_offering_id' => 1, 'course_id' => 1, 'academic_year_id' => 1, 'semester_id' => 1, 'academic_program_id' => 1, 'department_id' => 1],
            ['course_offering_id' => 2, 'course_id' => 2, 'academic_year_id' => 1, 'semester_id' => 1, 'academic_program_id' => 2, 'department_id' => 2],
            ['course_offering_id' => 3, 'course_id' => 3, 'academic_year_id' => 1, 'semester_id' => 1, 'academic_program_id' => 1, 'department_id' => 1],
        ]);

        DB::table('employee_types')->insert(['employee_type_id' => 1, 'type_code' => 'academic', 'type_name' => 'أكاديمي']);
        DB::table('employee_statuses')->insert([['employee_status_id' => 1, 'status_code' => 'active', 'status_name' => 'على رأس عمله'], ['employee_status_id' => 2, 'status_code' => 'retired', 'status_name' => 'متقاعد']]);
        DB::table('positions')->insert(['position_id' => 1, 'position_code' => 'DEAN', 'position_title' => 'عميد']);
        foreach ([[1, 'E-1', 'Admin'], [2, 'E-2', 'Vp'], [6, 'E-6', 'DeanA'], [8, 'E-8', 'Candidate'], [9, 'E-9', 'Exam'], [12, 'E-12', 'Wide'], [20, 'E-20', 'Free']] as [$id, $number, $last]) {
            $this->employee($id, $number, $last, $id === 6 ? 10 : null);
        }
        $this->employee(21, 'E-21', 'Retired', null, 2);
        $this->employee(31, 'E-31', 'TeacherA', 10);
        $this->employee(32, 'E-32', 'Loose', null);
        DB::table('faculty_members')->insert([
            ['faculty_member_id' => 1, 'employee_id' => 31, 'academic_rank' => 'مدرس', 'is_active' => 1],
            ['faculty_member_id' => 2, 'employee_id' => 32, 'academic_rank' => 'مدرس', 'is_active' => 1],
        ]);
        DB::table('employee_positions')->insert(['employee_id' => 6, 'position_id' => 1, 'organizational_unit_id' => 10, 'start_date' => '2025-01-01', 'is_primary' => 1, 'is_active' => 1]);

        DB::table('system_modules')->insert(['module_id' => 1, 'module_code' => 'vice_presidency', 'module_name' => 'VP']);
        DB::table('roles')->insert([
            ['role_id' => 1, 'role_code' => 'super_admin', 'role_name' => 'super'],
            ['role_id' => 2, 'role_code' => 'vice_president_administrative', 'role_name' => 'vp admin'],
            ['role_id' => 3, 'role_code' => 'dean', 'role_name' => 'dean'],
            ['role_id' => 4, 'role_code' => 'vice_president_scientific', 'role_name' => 'vp sci'],
            ['role_id' => 5, 'role_code' => 'doctor_instructor', 'role_name' => 'instructor'],
            ['role_id' => 6, 'role_code' => 'exam_committee', 'role_name' => 'exam'],
        ]);
        $permissions = [
            1 => 'vice_presidency.administrative.faculty.view', 2 => 'vice_presidency.administrative.faculty.manage',
            3 => 'vice_presidency.administrative.deans.view', 4 => 'vice_presidency.administrative.deans.manage',
            5 => 'vice_presidency.administrative.access', 6 => 'teaching_assignments.review_administrative',
            7 => 'teaching_assignments.view', 8 => 'teaching_assignments.manage', 9 => 'teaching_staff.view',
            10 => 'dashboards.view', 11 => 'teaching_assignments.review_scientific', 12 => 'vice_presidency.scientific.access',
        ];
        foreach ($permissions as $id => $code) {
            DB::table('permissions')->insert(['permission_id' => $id, 'module_id' => 1, 'permission_code' => $code, 'permission_name' => $code]);
        }
        $map = [2 => [1, 2, 3, 4, 5, 6, 7], 3 => [7, 8, 9, 10], 4 => [7, 11, 12]];
        foreach ($map as $roleId => $ids) {
            foreach ($ids as $permissionId) {
                DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }

        $this->user(1, 'admin', 1);
        $this->user(2, 'vp.admin', 2);
        $this->user(3, 'vp.noscope', null);
        $this->user(5, 'nobody', null);
        $this->user(6, 'dean.a', 6);
        $this->user(7, 'vp.multi', null);
        $this->user(8, 'candidate', 8);
        $this->user(9, 'exam.user', 9);
        $this->user(10, 'vp.sci', null);
        $this->user(11, 'vp.disabled', null, 2);
        $this->user(12, 'dean.wide', 12);
        $roles = [
            [1, 1], [2, 2], [3, 2],
            [5, 5], [6, 3], [7, 2],
            [7, 6], [8, 5], [9, 6],
            [10, 4], [11, 2], [12, 3],
        ];
        foreach ($roles as [$userId, $roleId]) {
            DB::table('user_roles')->insert(['user_id' => $userId, 'role_id' => $roleId, 'is_active' => 1]);
        }
        foreach ([1, 2, 7, 10, 11, 12] as $userId) {
            DB::table('user_access_scopes')->insert(['user_id' => $userId, 'scope_type' => 'university', 'scope_id' => 1, 'is_active' => 1]);
        }
        DB::table('user_access_scopes')->insert(['user_id' => 6, 'scope_type' => 'college', 'scope_id' => 1, 'is_active' => 1]);

        DB::table('student_statuses')->insert([['student_status_id' => 1, 'status_code' => 'active', 'status_name' => 'active'], ['student_status_id' => 2, 'status_code' => 'suspended', 'status_name' => 'suspended']]);
        $students = [[1, 1, 1, null], [2, 1, 1, null], [3, 1, 1, null], [4, 2, 1, null], [5, 1, 2, null], [6, 2, 1, '2026-01-01 00:00:00']];
        foreach ($students as [$id, $program, $status, $deleted]) {
            DB::table('students')->insert([
                'student_id' => $id, 'student_number' => 'S-'.$id, 'first_name' => 'S', 'last_name' => (string) $id,
                'academic_program_id' => $program, 'current_academic_level_id' => 1, 'enrollment_date' => '2025-09-01',
                'student_status_id' => $status, 'deleted_at' => $deleted,
            ]);
        }

        // Workflow requests: offering 1 (A) theoretical pending; offering 3 (A) returned by admin; offering 2 (B) approved.
        $requests = [[1, 1, 'submitted', 'pending'], [2, 3, 'returned', 'returned'], [3, 2, 'approved', 'approved']];
        foreach ($requests as [$id, $offering, $status, $adminStatus]) {
            DB::table('teaching_assignment_requests')->insert([
                'teaching_assignment_request_id' => $id, 'course_offering_id' => $offering, 'faculty_member_id' => 1,
                'instructor_role' => 'theoretical', 'status' => $status, 'submission_version' => 1, 'current_slot' => 1,
                'requested_by_user_id' => 6, 'submitted_at' => now(), 'action_type' => 'assign',
            ]);
            DB::table('teaching_assignment_reviews')->insert([
                ['teaching_assignment_request_id' => $id, 'review_authority' => 'scientific', 'status' => $status === 'approved' ? 'approved' : 'pending'],
                ['teaching_assignment_request_id' => $id, 'review_authority' => 'administrative', 'status' => $adminStatus],
            ]);
        }
        // A superseded (non-current) request is never counted.
        DB::table('teaching_assignment_requests')->insert([
            'teaching_assignment_request_id' => 4, 'course_offering_id' => 1, 'faculty_member_id' => 2, 'instructor_role' => 'theoretical',
            'status' => 'superseded', 'submission_version' => 1, 'current_slot' => null, 'requested_by_user_id' => 6, 'action_type' => 'assign',
        ]);
        DB::table('teaching_assignment_reviews')->insert(['teaching_assignment_request_id' => 4, 'review_authority' => 'administrative', 'status' => 'pending']);
    }
}
