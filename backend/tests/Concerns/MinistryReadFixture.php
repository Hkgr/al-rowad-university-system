<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/** Isolated synthetic read-model fixture shared without inheriting another portal\'s tests. */
trait MinistryReadFixture
{
    private function buildFixture(): void
    {
        if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'sqlite') {
            throw new \RuntimeException('Ministry portal fixture requires the isolated testing SQLite connection.');
        }
        Schema::dropAllTables();
        $ts = fn (Blueprint $t) => $t->timestamps();

        Schema::create('account_statuses', function (Blueprint $t) { $t->integer('account_status_id')->primary(); $t->string('status_code'); $t->string('status_name')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) {
            $t->increments('user_id'); $t->string('username'); $t->string('email'); $t->string('password_hash'); $t->integer('account_status_id');
            $t->integer('student_id')->nullable(); $t->integer('employee_id')->nullable(); $t->integer('board_member_id')->nullable(); $t->integer('failed_login_attempts')->default(0);
            $t->dateTime('last_login_at')->nullable(); $t->integer('created_by_user_id')->nullable(); $t->timestamps();
        });
        Schema::create('roles', function (Blueprint $t) { $t->increments('role_id'); $t->string('role_code')->unique(); $t->string('role_name'); $t->text('description')->nullable(); $t->boolean('is_system_role')->default(true); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('permissions', function (Blueprint $t) { $t->increments('permission_id'); $t->integer('module_id')->default(1); $t->string('permission_code')->unique(); $t->string('permission_name'); $t->text('description')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('role_permissions', function (Blueprint $t) { $t->increments('role_permission_id'); $t->integer('role_id'); $t->integer('permission_id'); $t->dateTime('granted_at')->nullable(); });
        Schema::create('user_roles', function (Blueprint $t) { $t->increments('user_role_id'); $t->integer('user_id'); $t->integer('role_id'); $t->integer('assigned_by_user_id')->nullable(); $t->dateTime('assigned_at')->nullable(); $t->boolean('is_active')->default(true); });
        Schema::create('user_access_scopes', function (Blueprint $t) { $t->increments('user_access_scope_id'); $t->integer('user_id'); $t->string('scope_type'); $t->integer('scope_id'); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('organizational_unit_types', function (Blueprint $t) { $t->integer('unit_type_id')->primary(); $t->string('type_code'); $t->string('type_name'); });
        Schema::create('organizational_units', function (Blueprint $t) { $t->integer('organizational_unit_id')->primary(); $t->string('unit_code')->nullable(); $t->string('unit_name'); $t->integer('unit_type_id'); $t->integer('parent_unit_id')->nullable(); $t->boolean('is_active')->default(true); });
        Schema::create('positions', function (Blueprint $t) { $t->integer('position_id')->primary(); $t->string('position_code'); $t->string('position_title'); });
        Schema::create('colleges', function (Blueprint $t) { $t->integer('college_id')->primary(); $t->integer('organizational_unit_id')->nullable(); $t->string('college_code'); $t->string('college_name'); $t->text('description')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('departments', function (Blueprint $t) { $t->integer('department_id')->primary(); $t->integer('college_id'); $t->integer('organizational_unit_id')->nullable(); $t->string('department_code'); $t->string('department_name'); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('academic_programs', function (Blueprint $t) {
            $t->integer('academic_program_id')->primary(); $t->integer('department_id'); $t->string('program_code'); $t->string('program_name'); $t->string('degree_level')->default('bachelor');
            $t->integer('total_credit_hours')->default(150); $t->integer('duration_years')->default(4); $t->boolean('is_active')->default(true); $t->string('plan_state')->default('legacy');
            $t->integer('default_academic_plan_version_id')->nullable(); $t->dateTime('archived_at')->nullable(); $t->timestamps();
        });
        Schema::create('academic_plan_versions', function (Blueprint $t) { $t->integer('academic_plan_version_id')->primary(); $t->integer('academic_program_id'); $t->integer('version_number'); $t->string('label'); $t->string('status'); $t->timestamps(); });
        Schema::create('student_statuses', function (Blueprint $t) { $t->integer('student_status_id')->primary(); $t->string('status_code'); $t->string('status_name'); });
        Schema::create('academic_levels', function (Blueprint $t) { $t->integer('academic_level_id')->primary(); $t->string('level_code'); $t->string('level_name'); $t->integer('level_order'); });
        Schema::create('academic_years', function (Blueprint $t) { $t->integer('academic_year_id')->primary(); $t->string('year_name'); $t->date('start_date'); $t->date('end_date'); $t->boolean('is_current')->default(false); $t->boolean('is_active')->default(true); });
        Schema::create('semesters', function (Blueprint $t) { $t->integer('semester_id')->primary(); $t->string('semester_code'); $t->string('semester_name'); $t->integer('semester_order'); });
        Schema::create('students', function (Blueprint $t) {
            $t->integer('student_id')->primary(); $t->string('student_number'); $t->string('first_name'); $t->string('last_name'); $t->string('father_name')->nullable(); $t->string('mother_name')->nullable();
            $t->date('date_of_birth')->nullable(); $t->string('gender')->nullable(); $t->string('phone_number')->nullable(); $t->string('email')->nullable(); $t->string('address')->nullable(); $t->string('nationality')->nullable();
            $t->integer('academic_program_id'); $t->integer('current_academic_level_id'); $t->date('enrollment_date'); $t->integer('student_status_id'); $t->text('deregistration_reason')->nullable();
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('student_academic_plan_assignments', function (Blueprint $t) { $t->increments('student_academic_plan_assignment_id'); $t->integer('student_id'); $t->integer('academic_program_id'); $t->integer('academic_plan_version_id'); $t->integer('current_slot')->nullable(); $t->string('reason'); $t->dateTime('assigned_at'); $t->dateTime('ended_at')->nullable(); });
        Schema::create('courses', function (Blueprint $t) { $t->integer('course_id')->primary(); $t->string('course_code'); $t->string('course_name'); $t->integer('credit_hours'); $t->integer('theoretical_hours')->nullable(); $t->integer('practical_hours')->nullable(); $t->text('description')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('course_departments', function (Blueprint $t) { $t->increments('course_department_id'); $t->integer('course_id'); $t->integer('department_id'); $t->boolean('is_primary')->default(false); });
        Schema::create('program_courses', function (Blueprint $t) { $t->increments('program_course_id'); $t->integer('academic_program_id'); $t->integer('course_id'); $t->integer('academic_level_id'); $t->integer('recommended_semester_id'); $t->string('course_type')->default('required'); $t->boolean('is_active')->default(true); $t->integer('academic_plan_version_id')->nullable(); });
        Schema::create('course_offerings', function (Blueprint $t) { $t->integer('course_offering_id')->primary(); $t->integer('course_id'); $t->integer('academic_year_id'); $t->integer('semester_id'); $t->integer('department_id')->nullable(); $t->integer('academic_program_id')->nullable(); $t->integer('capacity')->default(40); $t->string('status'); $t->timestamps(); });
        Schema::create('registration_statuses', function (Blueprint $t) { $t->integer('registration_status_id')->primary(); $t->string('status_code'); });
        Schema::create('result_statuses', function (Blueprint $t) { $t->integer('result_status_id')->primary(); $t->string('status_code'); });
        Schema::create('approval_statuses', function (Blueprint $t) { $t->integer('approval_status_id')->primary(); $t->string('status_code'); });
        Schema::create('student_course_registrations', function (Blueprint $t) { $t->integer('student_course_registration_id')->primary(); $t->integer('student_id'); $t->integer('course_offering_id'); $t->integer('registration_status_id'); $t->string('notes')->nullable(); });
        Schema::create('student_course_results', function (Blueprint $t) { $t->increments('student_course_result_id'); $t->integer('student_course_registration_id'); $t->decimal('final_mark', 5, 2); $t->integer('result_status_id'); });
        Schema::create('grade_approvals', function (Blueprint $t) { $t->integer('grade_approval_id')->primary(); $t->integer('course_offering_id'); $t->integer('approval_status_id'); $t->text('approval_notes')->nullable(); });
        Schema::create('student_academic_terms', function (Blueprint $t) { $t->increments('student_academic_term_id'); $t->integer('student_id'); $t->integer('academic_year_id'); $t->integer('semester_id'); $t->decimal('term_gpa', 4, 2)->nullable(); $t->decimal('cumulative_gpa', 4, 2)->nullable(); $t->integer('attempted_hours'); $t->integer('earned_hours'); $t->boolean('is_finalized'); });
        Schema::create('student_graduation_decisions', function (Blueprint $t) { $t->increments('student_graduation_decision_id'); $t->integer('student_id'); $t->string('status'); $t->decimal('cumulative_gpa_snapshot', 4, 2)->nullable(); $t->integer('earned_hours_snapshot')->default(0); $t->text('review_notes')->nullable(); $t->dateTime('approved_at')->nullable(); $t->dateTime('materialized_at')->nullable(); $t->dateTime('superseded_at')->nullable(); });
        Schema::create('employee_statuses', function (Blueprint $t) { $t->integer('employee_status_id')->primary(); $t->string('status_code'); });
        Schema::create('employees', function (Blueprint $t) { $t->integer('employee_id')->primary(); $t->string('employee_number'); $t->string('first_name'); $t->string('last_name'); $t->string('father_name')->nullable(); $t->string('phone_number')->nullable(); $t->string('email')->nullable(); $t->integer('employee_status_id')->default(1); $t->integer('organizational_unit_id')->nullable(); });
        Schema::create('faculty_members', function (Blueprint $t) { $t->integer('faculty_member_id')->primary(); $t->integer('employee_id'); $t->string('academic_rank')->nullable(); $t->string('specialization')->nullable(); $t->string('office_location')->nullable(); $t->boolean('is_active')->default(true); });
        Schema::create('employee_unit_assignments', function (Blueprint $t) { $t->increments('assignment_id'); $t->integer('employee_id'); $t->integer('organizational_unit_id'); $t->date('start_date'); $t->date('end_date')->nullable(); $t->boolean('is_active')->default(true); });
        Schema::create('employee_positions', function (Blueprint $t) { $t->increments('employee_position_id'); $t->integer('employee_id'); $t->integer('position_id'); $t->integer('organizational_unit_id')->nullable(); $t->date('start_date'); $t->date('end_date')->nullable(); $t->boolean('is_primary')->default(false); $t->boolean('is_active')->default(true); });
        Schema::create('course_offering_instructors', function (Blueprint $t) { $t->increments('course_offering_instructor_id'); $t->integer('course_offering_id'); $t->integer('faculty_member_id'); $t->string('instructor_role'); $t->boolean('is_primary')->default(true); $t->boolean('is_active')->default(true); });
        Schema::create('course_instructors', function (Blueprint $t) { $t->increments('course_instructor_id'); $t->integer('course_id'); $t->integer('faculty_member_id'); $t->boolean('is_active')->default(true); });

        DB::table('account_statuses')->insert([['account_status_id' => 1, 'status_code' => 'active'], ['account_status_id' => 2, 'status_code' => 'disabled']]);
        $roles = [1 => 'super_admin', 2 => 'ministry_observer', 3 => 'dean', 4 => 'registration_officer', 5 => 'vice_president_scientific', 6 => 'vice_president_administrative', 7 => 'vice_president'];
        foreach ($roles as $id => $code) {
            DB::table('roles')->insert(['role_id' => $id, 'role_code' => $code, 'role_name' => $code, 'is_active' => 1]);
        }
        $codes = ['ministry_portal.access', 'ministry_portal.dashboard.view', 'ministry_portal.deans.view', 'ministry_portal.students.view', 'ministry_portal.colleges.view',
            'ministry_portal.courses.view', 'ministry_portal.faculty.view', 'ministry_portal.leadership.view', 'students.view', 'registration.view'];
        foreach ($codes as $i => $code) {
            DB::table('permissions')->insert(['permission_id' => $i + 1, 'permission_code' => $code, 'permission_name' => $code, 'is_active' => 1]);
        }
        foreach (range(1, 8) as $p) {
            DB::table('role_permissions')->insert(['role_id' => self::ROLE_MINISTRY, 'permission_id' => $p, 'granted_at' => now()]);
        }
        DB::table('role_permissions')->insert([['role_id' => 4, 'permission_id' => 9, 'granted_at' => now()], ['role_id' => 4, 'permission_id' => 10, 'granted_at' => now()]]);
        $users = [1 => ['admin', null, 1], 2 => ['ministry.demo', null, 1], 3 => ['registrar', null, 1], 4 => ['dean.a', 1, 1], 5 => ['dean.b', null, 1], 6 => ['vp.sci', 50, 1], 7 => ['ministry.off', null, 2], 8 => ['plain', null, 1]];
        foreach ($users as $id => [$name, $employee, $status]) {
            DB::table('users')->insert(['user_id' => $id, 'username' => $name, 'email' => $name.'@example.invalid', 'password_hash' => 'x', 'account_status_id' => $status, 'employee_id' => $employee]);
        }
        foreach ([[1, 1], [2, 2], [3, 4], [4, 3], [5, 3], [6, 5], [7, 2]] as [$u, $r]) {
            DB::table('user_roles')->insert(['user_id' => $u, 'role_id' => $r, 'assigned_at' => '2026-09-01 10:00:00', 'is_active' => 1]);
        }
        DB::table('user_access_scopes')->insert([['user_id' => 4, 'scope_type' => 'college', 'scope_id' => 1, 'is_active' => 1], ['user_id' => 5, 'scope_type' => 'college', 'scope_id' => 2, 'is_active' => 1], ['user_id' => 6, 'scope_type' => 'university', 'scope_id' => 91, 'is_active' => 1]]);

        DB::table('organizational_unit_types')->insert([['unit_type_id' => 3, 'type_code' => 'presidency', 'type_name' => 'p'], ['unit_type_id' => 4, 'type_code' => 'vice_presidency', 'type_name' => 'v'], ['unit_type_id' => 5, 'type_code' => 'administration', 'type_name' => 'a'], ['unit_type_id' => 10, 'type_code' => 'college', 'type_name' => 'c']]);
        DB::table('organizational_units')->insert([
            ['organizational_unit_id' => 91, 'unit_code' => 'PRES', 'unit_name' => 'رئيس الجامعة', 'unit_type_id' => 3, 'parent_unit_id' => null],
            ['organizational_unit_id' => 93, 'unit_code' => '8', 'unit_name' => 'نائب رئيس الجامعة للشؤون العلمية', 'unit_type_id' => 4, 'parent_unit_id' => 91],
            ['organizational_unit_id' => 94, 'unit_code' => '7', 'unit_name' => 'نائب رئيس الجامعة للشؤون الإدارية', 'unit_type_id' => 4, 'parent_unit_id' => 91],
            ['organizational_unit_id' => 92, 'unit_code' => '9', 'unit_name' => 'نائب رئيس الجامعة للشؤون المجتمعية', 'unit_type_id' => 4, 'parent_unit_id' => 91],
            ['organizational_unit_id' => 110, 'unit_code' => '81', 'unit_name' => 'إدارة التعليم الجامعي', 'unit_type_id' => 5, 'parent_unit_id' => 93],
            ['organizational_unit_id' => 177, 'unit_code' => 'A', 'unit_name' => 'وحدة كلية ألف', 'unit_type_id' => 10, 'parent_unit_id' => 110],
            ['organizational_unit_id' => 179, 'unit_code' => 'B', 'unit_name' => 'وحدة كلية باء', 'unit_type_id' => 10, 'parent_unit_id' => 110],
            ['organizational_unit_id' => 178, 'unit_code' => 'C', 'unit_name' => 'وحدة كلية جيم', 'unit_type_id' => 10, 'parent_unit_id' => 110],
        ]);
        DB::table('positions')->insert([['position_id' => 1, 'position_code' => 'PRESIDENT', 'position_title' => 'President'], ['position_id' => 2, 'position_code' => 'VICE_PRESIDENT', 'position_title' => 'VP'], ['position_id' => 4, 'position_code' => 'DEAN', 'position_title' => 'Dean']]);
        DB::table('colleges')->insert([
            ['college_id' => 1, 'organizational_unit_id' => 177, 'college_code' => 'A', 'college_name' => 'كلية ألف', 'is_active' => 1],
            ['college_id' => 2, 'organizational_unit_id' => 179, 'college_code' => 'B', 'college_name' => 'كلية باء', 'is_active' => 1],
            ['college_id' => 3, 'organizational_unit_id' => 178, 'college_code' => 'C', 'college_name' => 'كلية جيم', 'is_active' => 1],
            ['college_id' => 4, 'organizational_unit_id' => null, 'college_code' => 'D', 'college_name' => 'كلية دال', 'is_active' => 0],
        ]);
        DB::table('departments')->insert([
            ['department_id' => 1, 'college_id' => 1, 'department_code' => 'D1', 'department_name' => 'قسم 1', 'is_active' => 1],
            ['department_id' => 2, 'college_id' => 2, 'department_code' => 'D2', 'department_name' => 'قسم 2', 'is_active' => 1],
            ['department_id' => 3, 'college_id' => 3, 'department_code' => 'D3', 'department_name' => 'قسم 3', 'is_active' => 1],
        ]);
        DB::table('academic_programs')->insert([
            ['academic_program_id' => 1, 'department_id' => 1, 'program_code' => 'P1', 'program_name' => 'برنامج 1', 'plan_state' => 'legacy', 'is_active' => 1, 'default_academic_plan_version_id' => null, 'archived_at' => null],
            ['academic_program_id' => 2, 'department_id' => 2, 'program_code' => 'P2', 'program_name' => 'برنامج 2', 'plan_state' => 'ready', 'is_active' => 1, 'default_academic_plan_version_id' => 2, 'archived_at' => null],
            ['academic_program_id' => 3, 'department_id' => 3, 'program_code' => 'P3', 'program_name' => 'برنامج 3', 'plan_state' => 'legacy', 'is_active' => 0, 'default_academic_plan_version_id' => null, 'archived_at' => '2025-01-01 00:00:00'],
        ]);
        DB::table('academic_plan_versions')->insert([
            ['academic_plan_version_id' => 1, 'academic_program_id' => 2, 'version_number' => 1, 'label' => 'خطة 1', 'status' => 'approved'],
            ['academic_plan_version_id' => 2, 'academic_program_id' => 2, 'version_number' => 2, 'label' => 'خطة 2', 'status' => 'approved'],
            ['academic_plan_version_id' => 3, 'academic_program_id' => 2, 'version_number' => 3, 'label' => 'مسودة 3', 'status' => 'draft'],
        ]);
        DB::table('student_statuses')->insert([['student_status_id' => 1, 'status_code' => 'active', 'status_name' => 'Active'], ['student_status_id' => 2, 'status_code' => 'frozen', 'status_name' => 'Frozen'], ['student_status_id' => 3, 'status_code' => 'graduated', 'status_name' => 'Graduated'], ['student_status_id' => 4, 'status_code' => 'withdrawn', 'status_name' => 'Withdrawn']]);
        DB::table('academic_levels')->insert([['academic_level_id' => 1, 'level_code' => 'year_1', 'level_name' => 'Year 1', 'level_order' => 1], ['academic_level_id' => 2, 'level_code' => 'year_2', 'level_name' => 'Year 2', 'level_order' => 2]]);
        DB::table('academic_years')->insert([
            ['academic_year_id' => 10, 'year_name' => '2024-2025', 'start_date' => '2024-09-01', 'end_date' => '2025-08-31', 'is_current' => 0, 'is_active' => 1],
            ['academic_year_id' => 11, 'year_name' => '2025-2026', 'start_date' => '2025-09-01', 'end_date' => '2026-08-31', 'is_current' => 1, 'is_active' => 1],
        ]);
        DB::table('semesters')->insert([['semester_id' => 1, 'semester_code' => 'first', 'semester_name' => 'First', 'semester_order' => 1], ['semester_id' => 2, 'semester_code' => 'second', 'semester_name' => 'Second', 'semester_order' => 2]]);
        $students = [
            [1, 'S-001', 'سامر', 'نبيل', 'الحلبي', 1, 1, 2, '2024-09-10', null],
            [2, 'S-002', 'ريم', 'عادل', 'الشامي', 1, 1, 1, '2025-09-10', null],
            [3, 'S-003', 'خالد', 'وليد', 'العلي', 1, 2, 2, '2024-09-12', null],
            [4, 'S-004', 'لينا', 'جمال', 'النجار', 2, 1, 1, '2025-09-15', null],
            [5, 'S-005', 'رامي', 'كمال', 'الحداد', 2, 3, 2, '2024-09-15', null],
            [6, 'S-006', 'هبة', 'فريد', 'المصري', 2, 4, 1, '2025-09-20', null],
            [7, 'S-007', 'باسل', 'منير', 'الصباغ', 1, 1, 1, '2025-09-10', '2026-01-01 10:00:00'],
            [8, 'S-008', 'نور', 'سمير', 'اليوسف', 3, 1, 1, '2024-09-01', null],
        ];
        foreach ($students as [$id, $number, $first, $father, $last, $program, $status, $level, $enrolled, $deleted]) {
            DB::table('students')->insert(['student_id' => $id, 'student_number' => $number, 'first_name' => $first, 'father_name' => $father, 'last_name' => $last,
                'mother_name' => 'الأم السرية', 'date_of_birth' => '2003-05-05', 'gender' => 'male', 'phone_number' => '0999000'.$id, 'email' => 'student'.$id.'@example.invalid',
                'address' => 'عنوان سري', 'nationality' => 'X', 'academic_program_id' => $program, 'current_academic_level_id' => $level, 'enrollment_date' => $enrolled,
                'student_status_id' => $status, 'deregistration_reason' => 'سبب داخلي', 'deleted_at' => $deleted]);
        }
        DB::table('student_academic_plan_assignments')->insert(['student_id' => 4, 'academic_program_id' => 2, 'academic_plan_version_id' => 2, 'current_slot' => 1, 'reason' => 'x', 'assigned_at' => '2025-09-15 10:00:00']);
        foreach (range(1, 8) as $c) {
            DB::table('courses')->insert(['course_id' => $c, 'course_code' => 'CRS'.$c, 'course_name' => 'مقرر '.$c, 'credit_hours' => 3, 'is_active' => $c === 8 ? 0 : 1]);
        }
        foreach ([[1, 1, 1], [2, 1, 1], [3, 1, 1], [8, 1, 1], [4, 2, 1], [5, 2, 1], [6, 2, 1], [7, 2, 1], [1, 2, 0]] as [$c, $dep, $primary]) {
            DB::table('course_departments')->insert(['course_id' => $c, 'department_id' => $dep, 'is_primary' => $primary]);
        }
        foreach ([[1, 1, null], [1, 2, null], [1, 3, null], [2, 4, 1], [2, 5, 1], [2, 4, 2], [2, 6, 2], [2, 4, 3], [2, 6, 3], [2, 7, 3]] as [$p, $c, $v]) {
            DB::table('program_courses')->insert(['academic_program_id' => $p, 'course_id' => $c, 'academic_level_id' => 1, 'recommended_semester_id' => 1, 'academic_plan_version_id' => $v]);
        }
        DB::table('course_offerings')->insert([
            ['course_offering_id' => 1, 'course_id' => 1, 'academic_year_id' => 10, 'semester_id' => 1, 'department_id' => 1, 'academic_program_id' => 1, 'status' => 'closed'],
            ['course_offering_id' => 2, 'course_id' => 2, 'academic_year_id' => 10, 'semester_id' => 2, 'department_id' => 1, 'academic_program_id' => 1, 'status' => 'closed'],
            ['course_offering_id' => 3, 'course_id' => 4, 'academic_year_id' => 11, 'semester_id' => 1, 'department_id' => 2, 'academic_program_id' => 2, 'status' => 'open'],
            ['course_offering_id' => 4, 'course_id' => 6, 'academic_year_id' => 11, 'semester_id' => 1, 'department_id' => 2, 'academic_program_id' => 2, 'status' => 'closed'],
            ['course_offering_id' => 5, 'course_id' => 1, 'academic_year_id' => 11, 'semester_id' => 1, 'department_id' => 1, 'academic_program_id' => 1, 'status' => 'open'],
        ]);
        DB::table('registration_statuses')->insert([['registration_status_id' => 1, 'status_code' => 'registered'], ['registration_status_id' => 2, 'status_code' => 'dropped'], ['registration_status_id' => 4, 'status_code' => 'completed']]);
        DB::table('result_statuses')->insert([['result_status_id' => 1, 'status_code' => 'passed'], ['result_status_id' => 2, 'status_code' => 'failed'], ['result_status_id' => 3, 'status_code' => 'deprived']]);
        DB::table('approval_statuses')->insert([['approval_status_id' => 1, 'status_code' => 'pending'], ['approval_status_id' => 2, 'status_code' => 'approved'], ['approval_status_id' => 4, 'status_code' => 'returned_for_correction']]);
        DB::table('grade_approvals')->insert([
            ['grade_approval_id' => 1, 'course_offering_id' => 1, 'approval_status_id' => 2, 'approval_notes' => 'ملاحظة داخلية'],
            ['grade_approval_id' => 2, 'course_offering_id' => 2, 'approval_status_id' => 2, 'approval_notes' => null],
            ['grade_approval_id' => 3, 'course_offering_id' => 2, 'approval_status_id' => 4, 'approval_notes' => 'ملاحظة داخلية'],
            ['grade_approval_id' => 4, 'course_offering_id' => 3, 'approval_status_id' => 1, 'approval_notes' => null],
            ['grade_approval_id' => 5, 'course_offering_id' => 4, 'approval_status_id' => 2, 'approval_notes' => null],
        ]);
        // [registration, student, offering, status, mark, result]
        foreach ([[1, 1, 1, 4, 75, 1], [2, 3, 1, 4, 40, 2], [3, 7, 1, 4, 80, 1], [4, 1, 2, 4, 90, 1], [5, 4, 3, 1, 55, 2], [6, 4, 4, 4, 80, 1], [7, 5, 4, 4, 0, 3], [8, 6, 4, 2, 70, 1], [9, 2, 5, 1, null, null]] as [$r, $s, $o, $st, $mark, $res]) {
            DB::table('student_course_registrations')->insert(['student_course_registration_id' => $r, 'student_id' => $s, 'course_offering_id' => $o, 'registration_status_id' => $st, 'notes' => 'ملاحظة داخلية']);
            if ($mark !== null) {
                DB::table('student_course_results')->insert(['student_course_registration_id' => $r, 'final_mark' => $mark, 'result_status_id' => $res]);
            }
        }
        DB::table('student_academic_terms')->insert([
            ['student_id' => 1, 'academic_year_id' => 10, 'semester_id' => 1, 'term_gpa' => 3.1, 'cumulative_gpa' => 3.1, 'attempted_hours' => 3, 'earned_hours' => 3, 'is_finalized' => 1],
            ['student_id' => 1, 'academic_year_id' => 10, 'semester_id' => 2, 'term_gpa' => 3.5, 'cumulative_gpa' => 3.3, 'attempted_hours' => 3, 'earned_hours' => 3, 'is_finalized' => 0],
        ]);
        DB::table('student_graduation_decisions')->insert([
            ['student_id' => 5, 'status' => 'approved', 'review_notes' => 'ملاحظة داخلية', 'approved_at' => '2026-02-10 10:00:00', 'materialized_at' => '2026-02-11 10:00:00', 'superseded_at' => null],
            ['student_id' => 1, 'status' => 'approved', 'review_notes' => null, 'approved_at' => '2026-02-10 10:00:00', 'materialized_at' => '2026-02-11 10:00:00', 'superseded_at' => '2026-03-01 10:00:00'],
            ['student_id' => 2, 'status' => 'submitted', 'review_notes' => null, 'approved_at' => null, 'materialized_at' => null, 'superseded_at' => null],
            ['student_id' => 7, 'status' => 'approved', 'review_notes' => null, 'approved_at' => '2026-02-10 10:00:00', 'materialized_at' => '2026-02-11 10:00:00', 'superseded_at' => null],
        ]);

        DB::table('employee_statuses')->insert([['employee_status_id' => 1, 'status_code' => 'active']]);
        foreach ([[1, 'سامي', 'الأحمد', 177], [2, 'رنا', 'الحسن', 179], [3, 'ماهر', 'العمر', null], [4, 'فادي', 'القاسم', 177], [5, 'خالد', 'الحداد', null], [9, 'نزار', 'السيد', 177], [50, 'غسان', 'المحمود', null], [51, 'طارق', 'الزعبي', null]] as [$id, $first, $last, $unit]) {
            DB::table('employees')->insert(['employee_id' => $id, 'employee_number' => 'E-'.(1000 + $id), 'first_name' => $first, 'last_name' => $last, 'phone_number' => '0999111'.$id, 'email' => 'emp'.$id.'@example.invalid', 'organizational_unit_id' => $unit]);
        }
        foreach ([[1, 1, 1], [2, 2, 1], [3, 3, 1], [4, 4, 0], [5, 5, 1]] as [$fm, $e, $active]) {
            DB::table('faculty_members')->insert(['faculty_member_id' => $fm, 'employee_id' => $e, 'academic_rank' => 'أستاذ', 'specialization' => 'تخصص', 'office_location' => 'مكتب سري', 'is_active' => $active]);
        }
        DB::table('employee_unit_assignments')->insert([
            ['employee_id' => 2, 'organizational_unit_id' => 177, 'start_date' => '2025-02-01', 'end_date' => null, 'is_active' => 1],
            ['employee_id' => 3, 'organizational_unit_id' => 178, 'start_date' => '2024-09-01', 'end_date' => null, 'is_active' => 1],
            ['employee_id' => 5, 'organizational_unit_id' => 179, 'start_date' => '2022-09-01', 'end_date' => '2024-08-31', 'is_active' => 1],
        ]);
        DB::table('employee_positions')->insert([
            ['employee_id' => 1, 'position_id' => 4, 'organizational_unit_id' => 177, 'start_date' => '2024-09-01', 'end_date' => null, 'is_primary' => 1, 'is_active' => 1],
            ['employee_id' => 9, 'position_id' => 4, 'organizational_unit_id' => 177, 'start_date' => '2020-09-01', 'end_date' => '2024-08-31', 'is_primary' => 1, 'is_active' => 0],
            ['employee_id' => 50, 'position_id' => 2, 'organizational_unit_id' => 93, 'start_date' => '2023-10-01', 'end_date' => null, 'is_primary' => 1, 'is_active' => 1],
            ['employee_id' => 51, 'position_id' => 2, 'organizational_unit_id' => 93, 'start_date' => '2019-10-01', 'end_date' => '2023-09-30', 'is_primary' => 1, 'is_active' => 0],
        ]);
        DB::table('course_offering_instructors')->insert([
            ['course_offering_id' => 1, 'faculty_member_id' => 1, 'instructor_role' => 'theoretical', 'is_primary' => 1, 'is_active' => 1],
            ['course_offering_id' => 4, 'faculty_member_id' => 2, 'instructor_role' => 'theoretical', 'is_primary' => 1, 'is_active' => 1],
            ['course_offering_id' => 3, 'faculty_member_id' => 2, 'instructor_role' => 'practical', 'is_primary' => 0, 'is_active' => 0],
        ]);
        DB::table('course_instructors')->insert(['course_id' => 1, 'faculty_member_id' => 1, 'is_active' => 1]);
    }
}
