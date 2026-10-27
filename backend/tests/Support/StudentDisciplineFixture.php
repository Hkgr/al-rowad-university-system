<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Synthetic data only. Also reusable by the local browser acceptance server. */
final class StudentDisciplineFixture
{
    public static function install(): void
    {
        $database = (string) config('database.connections.sqlite.database');
        if (! app()->environment('testing') || DB::getDriverName() !== 'sqlite'
            || ($database !== ':memory:' && ! str_starts_with(basename($database), 'student-discipline-'))) {
            throw new \LogicException('This fixture requires an explicitly isolated testing SQLite database.');
        }
        Schema::dropAllTables();
        $tables = [
            'account_statuses' => ['account_status_id', 'status_code'],
            'users' => ['user_id', 'username', 'email', 'password_hash', 'account_status_id:int', 'employee_id:int', 'student_id:int'],
            'roles' => ['role_id', 'role_code', 'is_active:bool'],
            'user_roles' => ['user_role_id', 'user_id:int', 'role_id:int', 'is_active:bool', 'assigned_at'],
            'permissions' => ['permission_id', 'permission_code', 'is_active:bool'],
            'role_permissions' => ['role_permission_id', 'role_id:int', 'permission_id:int'],
            'user_access_scopes' => ['user_access_scope_id', 'user_id:int', 'scope_type', 'scope_id:int', 'is_active:bool'],
            'organizational_units' => ['organizational_unit_id', 'unit_code'],
            'colleges' => ['college_id', 'college_name'],
            'departments' => ['department_id', 'college_id:int'],
            'academic_programs' => ['academic_program_id', 'department_id:int', 'program_name', 'program_code'],
            'academic_levels' => ['academic_level_id', 'level_name'],
            'student_statuses' => ['student_status_id', 'status_code', 'status_name'],
            'document_types' => ['document_type_id', 'type_code', 'type_name', 'is_active:bool'],
            'student_documents' => ['student_document_id', 'student_id:int', 'document_type_id:int'],
            'students' => ['student_id', 'student_number', 'first_name', 'father_name', 'last_name', 'mother_name', 'phone_number', 'academic_program_id:int', 'current_academic_level_id:int', 'student_status_id:int', 'deleted_at'],
            'academic_years' => ['academic_year_id', 'year_name'],
            'semesters' => ['semester_id', 'semester_name'],
            'courses' => ['course_id', 'course_code', 'course_name'],
            'course_offerings' => ['course_offering_id', 'course_id:int', 'academic_program_id:int', 'department_id:int', 'academic_year_id:int', 'semester_id:int', 'faculty_member_id:int'],
            'faculty_members' => ['faculty_member_id', 'employee_id:int'],
            'course_offering_instructors' => ['course_offering_instructor_id', 'course_offering_id:int', 'faculty_member_id:int', 'is_active:bool'],
            'student_course_registrations' => ['student_course_registration_id', 'student_id:int', 'course_offering_id:int'],
            'student_course_results' => ['student_course_result_id', 'student_course_registration_id:int', 'final_mark', 'result_status_id:int', 'result_announced_at'],
            'grade_components' => ['grade_component_id', 'course_offering_id:int', 'component_type', 'exam_date'],
            'disciplinary_violation_types' => ['violation_type_id', 'violation_name_ar', 'is_active:bool'],
            'disciplinary_penalty_types' => ['penalty_type_id', 'penalty_name_ar', 'severity_order:int', 'is_active:bool', 'requires_investigation:bool', 'cascades_to_subsequent_courses:bool'],
            'student_disciplinary_cases' => ['case_id', 'student_id:int', 'violation_type_id:int', 'trigger_course_offering_id:int', 'violation_description', 'violation_date', 'reported_by_user_id:int', 'investigation_status', 'investigation_date', 'investigation_notes', 'decided_by_authority', 'decided_by_user_id:int', 'decision_number', 'decision_date', 'penalty_type_id:int', 'penalty_start_date', 'penalty_end_date', 'is_in_absentia:bool', 'guardian_notified_at', 'case_status'],
            'disciplinary_case_affected_courses' => ['affected_course_id', 'case_id:int', 'course_offering_id:int', 'previous_result_status_id:int', 'previous_theoretical_mark', 'previous_practical_mark', 'previous_coursework_mark', 'previous_final_mark', 'applied_at', 'reverted_at'],
            'appeal_statuses' => ['appeal_status_id', 'status_code', 'status_name'],
            'disciplinary_case_appeals' => ['appeal_id', 'case_id:int', 'submitted_at', 'appeal_reason', 'appeal_status_id:int', 'reviewed_by_user_id:int', 'decision_date', 'decision_notes'],
        ];
        foreach ($tables as $table => $columns) {
            Schema::create($table, function (Blueprint $t) use ($columns) {
                $t->increments(array_shift($columns));
                foreach ($columns as $definition) {
                    [$name, $type] = array_pad(explode(':', $definition), 2, 'text');
                    match ($type) {
                        'int' => $t->integer($name)->nullable(), 'bool' => $t->boolean($name)->default(false), default => $t->text($name)->nullable()
                    };
                }
                $t->timestamps();
            });
        }
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->morphs('tokenable');
            $t->string('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        DB::table('account_statuses')->insert([['account_status_id' => 1, 'status_code' => 'active'], ['account_status_id' => 2, 'status_code' => 'disabled']]);
        DB::table('organizational_units')->insert(['organizational_unit_id' => 1, 'unit_code' => 'PRES']);
        foreach ([1, 2] as $id) {
            DB::table('colleges')->insert(['college_id' => $id, 'college_name' => 'كلية اختبار '.$id]);
            DB::table('departments')->insert(['department_id' => $id, 'college_id' => $id]);
            DB::table('academic_programs')->insert(['academic_program_id' => $id, 'department_id' => $id, 'program_name' => 'برنامج اصطناعي '.$id, 'program_code' => 'SYN-P'.$id]);
        }
        DB::table('academic_levels')->insert(['academic_level_id' => 1, 'level_name' => 'الأولى']);
        DB::table('student_statuses')->insert(['student_status_id' => 1, 'status_code' => 'active', 'status_name' => 'نشط']);
        foreach ([11 => 1, 12 => 2, 13 => 1] as $id => $program) {
            DB::table('students')->insert(['student_id' => $id, 'student_number' => 'SYN-'.$id, 'first_name' => 'طالب', 'father_name' => 'اختبار', 'last_name' => 'اصطناعي '.$id,
                'mother_name' => 'PRIVATE-MOTHER', 'phone_number' => 'PRIVATE-PHONE', 'academic_program_id' => $program, 'current_academic_level_id' => 1, 'student_status_id' => 1, 'deleted_at' => $id === 13 ? '2026-01-01' : null]);
        }
        DB::table('disciplinary_violation_types')->insert(['violation_type_id' => 1, 'violation_name_ar' => 'مخالفة اصطناعية', 'is_active' => 1]);
        foreach ([1, 2] as $id) {
            DB::table('disciplinary_penalty_types')->insert(['penalty_type_id' => $id, 'penalty_name_ar' => $id === 1 ? 'إنذار تأديبي اصطناعي' : 'عقوبة صفر اصطناعية', 'severity_order' => $id,
                'is_active' => 1, 'requires_investigation' => 0, 'cascades_to_subsequent_courses' => $id === 2]);
        }
        foreach ([1 => 'submitted', 2 => 'accepted', 3 => 'rejected'] as $id => $code) {
            DB::table('appeal_statuses')->insert(['appeal_status_id' => $id, 'status_code' => $code, 'status_name' => $code]);
        }
        foreach ([1 => 11, 2 => 12, 3 => 13] as $id => $student) {
            DB::table('student_disciplinary_cases')->insert([
                'case_id' => $id, 'student_id' => $student, 'violation_type_id' => 1, 'penalty_type_id' => 1, 'violation_description' => 'وصف قرار اصطناعي محفوظ',
                'violation_date' => '2026-10-01', 'decision_number' => 'SYN-'.$id, 'decision_date' => '2026-10-02', 'decided_by_authority' => 'dean_or_institute_director', 'case_status' => 'active',
            ]);
        }
        DB::table('courses')->insert(['course_id' => 1, 'course_code' => 'SYN101', 'course_name' => 'مقرر اصطناعي']);
        DB::table('academic_years')->insert(['academic_year_id' => 1, 'year_name' => 'سنة اختبار']);
        DB::table('semesters')->insert(['semester_id' => 1, 'semester_name' => 'فصل اختبار']);
        DB::table('course_offerings')->insert(['course_offering_id' => 1, 'course_id' => 1, 'academic_program_id' => 1, 'department_id' => 1, 'academic_year_id' => 1, 'semester_id' => 1]);
        DB::table('student_course_registrations')->insert(['student_course_registration_id' => 1, 'student_id' => 11, 'course_offering_id' => 1]);
        DB::table('student_course_results')->insert(['student_course_result_id' => 1, 'student_course_registration_id' => 1, 'final_mark' => '81.50', 'result_status_id' => 1, 'result_announced_at' => '2026-10-01 12:00:00']);
    }

    public static function actor(string $role = 'registration_officer', array $permissions = ['students.view', 'students.manage'], ?string $scope = 'program', int $scopeId = 1): User
    {
        $roleId = DB::table('roles')->insertGetId(['role_code' => $role, 'is_active' => 1]);
        $id = DB::table('users')->insertGetId(['username' => 'synthetic-'.$roleId, 'account_status_id' => 1]);
        DB::table('user_roles')->insert(['user_id' => $id, 'role_id' => $roleId, 'is_active' => 1, 'assigned_at' => '2026-01-01']);
        foreach ($permissions as $permission) {
            $permissionId = DB::table('permissions')->insertGetId(['permission_code' => $permission, 'is_active' => 1]);
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        if ($scope) {
            DB::table('user_access_scopes')->insert(['user_id' => $id, 'scope_type' => $scope, 'scope_id' => $scopeId, 'is_active' => 1]);
        }

        return User::findOrFail($id);
    }
}
