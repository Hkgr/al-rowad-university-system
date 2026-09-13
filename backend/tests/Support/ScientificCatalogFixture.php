<?php

namespace Tests\Support;

use App\Support\ScientificCourseAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Shared test-only SQLite fixture; never a production schema installer. */
final class ScientificCatalogFixture
{
    public static function initialize(): void
    {
        if (!app()->environment('testing') || DB::connection()->getDriverName() !== 'sqlite') {
            throw new \RuntimeException('Catalog fixture requires an isolated testing SQLite connection.');
        }
        Schema::dropAllTables();
        self::schema();
        DB::table('academic_catalog_control')->insert(['control_id' => 1, 'revision' => 1, 'schema_version' => 1, 'is_ready' => 1]);
        DB::table('account_statuses')->insert(['account_status_id' => 1, 'status_code' => 'active']);
        DB::table('users')->insert(['user_id' => 1, 'username' => 'scientific', 'account_status_id' => 1]);
        DB::table('roles')->insert(['role_id' => 1, 'role_code' => 'vice_president_scientific']);
        DB::table('user_roles')->insert(['user_id' => 1, 'role_id' => 1]);
        foreach (['vice_presidency.scientific.access', ScientificCourseAccess::VIEW, ScientificCourseAccess::MANAGE, 'courses.manage', 'academic_structure.manage'] as $i => $code) {
            DB::table('permissions')->insert(['permission_id' => $i + 1, 'permission_code' => $code]);
            DB::table('role_permissions')->insert(['role_id' => 1, 'permission_id' => $i + 1]);
        }
        DB::table('organizational_units')->insert(['organizational_unit_id' => 1, 'unit_code' => 'PRES']);
        DB::table('user_access_scopes')->insert(['user_id' => 1, 'scope_type' => 'university', 'scope_id' => 1]);
        foreach ([1, 2] as $id) {
            DB::table('colleges')->insert(['college_id' => $id, 'college_name' => 'كلية '.$id]);
            DB::table('departments')->insert(['department_id' => $id, 'college_id' => $id, 'department_name' => 'قسم '.$id]);
            DB::table('academic_programs')->insert(['academic_program_id' => $id, 'department_id' => $id, 'program_name' => 'برنامج '.$id, 'total_credit_hours' => 3]);
            DB::table('courses')->insert(['course_id' => $id, 'course_code' => 'C'.$id, 'course_name' => 'مادة '.$id, 'credit_hours' => 3, 'theoretical_hours' => 2, 'practical_hours' => 0]);
            DB::table('course_departments')->insert(['course_id' => $id, 'department_id' => $id]);
        }
        DB::table('academic_levels')->insert(['academic_level_id' => 1, 'level_name' => 'الأول']);
        DB::table('semesters')->insert(['semester_id' => 1, 'semester_name' => 'الأول']);
        DB::table('result_statuses')->insert(['result_status_id' => 1, 'status_code' => 'passed', 'status_name' => 'ناجح']);
    }

    private static function schema(): void
    {
        $tables = [
            'account_statuses' => ['account_status_id', ['status_code']], 'users' => ['user_id', ['username'], ['account_status_id', 'student_id', 'employee_id']],
            'roles' => ['role_id', ['role_code']], 'permissions' => ['permission_id', ['permission_code']],
            'role_permissions' => ['role_permission_id', [], ['role_id', 'permission_id']], 'user_roles' => ['user_role_id', [], ['user_id', 'role_id']],
            'organizational_units' => ['organizational_unit_id', ['unit_code']], 'user_access_scopes' => ['user_access_scope_id', ['scope_type'], ['user_id', 'scope_id']],
            'academic_catalog_control' => ['control_id', [], ['schema_version', 'revision', 'is_ready']],
            'colleges' => ['college_id', ['college_name']], 'departments' => ['department_id', ['department_name'], ['college_id']],
            'academic_programs' => ['academic_program_id', ['program_name', 'program_code', 'degree_level', 'description'], ['department_id', 'total_credit_hours', 'duration_years']],
            'academic_levels' => ['academic_level_id', ['level_name']], 'semesters' => ['semester_id', ['semester_name']],
            'courses' => ['course_id', ['course_code', 'course_name', 'description'], ['credit_hours', 'theoretical_hours', 'practical_hours']],
            'course_departments' => ['course_department_id', [], ['course_id', 'department_id', 'is_primary']],
            'course_instructors' => ['course_instructor_id', [], ['course_id', 'faculty_member_id', 'is_primary']],
            'faculty_members' => ['faculty_member_id', [], ['employee_id']], 'employees' => ['employee_id', ['first_name', 'last_name']],
            'course_prerequisites' => ['course_prerequisite_id', [], ['course_id', 'prerequisite_course_id', 'minimum_result_status_id']],
            'result_statuses' => ['result_status_id', ['status_name', 'status_code']],
            'program_courses' => ['program_course_id', ['course_type'], ['academic_program_id', 'course_id', 'academic_level_id', 'recommended_semester_id']],
            'academic_requirement_groups' => ['requirement_group_id', ['group_code', 'group_name', 'requirement_scope', 'requirement_type'], ['academic_program_id', 'required_credit_hours']],
            'program_course_requirement_groups' => ['program_course_requirement_group_id', [], ['program_course_id', 'requirement_group_id']],
            'user_activity_logs' => ['activity_log_id', ['module_code', 'action_code', 'description', 'ip_address'], ['user_id']],
        ];
        foreach (\App\Services\AcademicCatalogHistory::PROGRAM_REFERENCES as $table => [$key, $foreign]) $tables[$table] = [$key, [], [$foreign, ...in_array($table, ['course_offerings', 'supplementary_exam_offerings']) ? ['course_id'] : []]];
        foreach ($tables as $table => $definition) Schema::create($table, function (Blueprint $t) use ($table, $definition) {
            $t->increments($definition[0]);
            foreach ($definition[1] as $field) $t->string($field)->nullable()->collation('nocase');
            foreach ($definition[2] ?? [] as $field) $t->integer($field)->nullable();
            $t->boolean('is_active')->default(true); $t->timestamps();
            if ($table === 'students') $t->softDeletes();
            if ($table === 'courses') $t->unique('course_code');
            if ($table === 'program_courses') $t->unique(['academic_program_id', 'course_id']);
            if ($table === 'academic_requirement_groups') { $t->unique('group_code'); $t->unique(['academic_program_id', 'requirement_scope', 'requirement_type']); }
            if ($table === 'program_course_requirement_groups') $t->unique('program_course_id');
            $foreign = match ($table) {
                'course_departments' => ['course_id' => ['courses', 'course_id'], 'department_id' => ['departments', 'department_id']],
                'course_prerequisites' => ['course_id' => ['courses', 'course_id'], 'prerequisite_course_id' => ['courses', 'course_id'], 'minimum_result_status_id' => ['result_statuses', 'result_status_id']],
                'program_courses' => ['course_id' => ['courses', 'course_id'], 'academic_program_id' => ['academic_programs', 'academic_program_id'], 'academic_level_id' => ['academic_levels', 'academic_level_id'], 'recommended_semester_id' => ['semesters', 'semester_id']],
                'academic_requirement_groups' => ['academic_program_id' => ['academic_programs', 'academic_program_id']],
                'program_course_requirement_groups' => ['program_course_id' => ['program_courses', 'program_course_id'], 'requirement_group_id' => ['academic_requirement_groups', 'requirement_group_id']],
                'course_offerings' => ['course_id' => ['courses', 'course_id'], 'academic_program_id' => ['academic_programs', 'academic_program_id']],
                'students' => ['academic_program_id' => ['academic_programs', 'academic_program_id']],
                default => [],
            };
            foreach ($foreign as $column => [$target, $key]) $t->foreign($column)->references($key)->on($target)->restrictOnDelete()->restrictOnUpdate();
        });
        foreach (['courses', 'academic_programs', 'program_courses', 'academic_requirement_groups', 'program_course_requirement_groups', 'course_departments', 'course_prerequisites', ...array_keys(\App\Services\AcademicCatalogHistory::PROGRAM_REFERENCES)] as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) DB::unprepared("CREATE TRIGGER epoch_{$table}_{$event} AFTER {$event} ON {$table} BEGIN UPDATE academic_catalog_control SET revision=revision+1 WHERE control_id=1; END");
        }
        DB::unprepared("CREATE TRIGGER used_course BEFORE UPDATE ON courses WHEN (NEW.credit_hours IS NOT OLD.credit_hours OR NEW.is_active IS NOT OLD.is_active) AND (EXISTS(SELECT 1 FROM course_offerings WHERE course_id=OLD.course_id) OR EXISTS(SELECT 1 FROM program_courses pc JOIN students s ON s.academic_program_id=pc.academic_program_id WHERE pc.course_id=OLD.course_id)) BEGIN SELECT RAISE(ABORT,'academic_catalog_history_locked'); END");
    }
}
