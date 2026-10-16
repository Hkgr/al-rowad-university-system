<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/** Synthetic legacy projection; never imports the supplied production records. */
final class CourseResultSymbolsFixture
{
    public static function installLegacy(): void { self::schema(); self::seedManualGradeFixture(); }
    public static function installLifecycle(): void
    {
        Schema::table('user_roles', fn (Blueprint $t) => $t->dateTime('assigned_at')->nullable());
        Schema::table('student_course_results', function (Blueprint $t): void { $t->dateTime('result_announced_at')->nullable(); $t->unique('student_course_registration_id'); });
        Schema::table('student_course_registrations', fn (Blueprint $t) => $t->unique(['student_id', 'course_offering_id']));
        Schema::create('student_course_incomplete_cases', function (Blueprint $t): void {
            $t->increments('incomplete_case_id');
            foreach (['student_course_registration_id','student_course_result_id','student_id','course_id','academic_year_id','semester_id','granted_by_user_id'] as $f) $t->integer($f);
            foreach (['reason','requirements','deadline_source','expiry_outcome','state'] as $f) $t->text($f);
            foreach (['granted_at','deadline_at'] as $f) $t->dateTime($f);
            $t->dateTime('settled_at')->nullable(); $t->text('calendar_snapshot')->nullable(); $t->text('outcome_snapshot'); $t->unsignedInteger('revision'); $t->timestamps();
            $t->unique('student_course_registration_id'); $t->unique('student_course_result_id');
        });
        Schema::create('student_course_incomplete_events', function (Blueprint $t): void {
            $t->bigIncrements('incomplete_event_id'); $t->integer('incomplete_case_id'); $t->unsignedInteger('revision');
            foreach (['action','request_id','request_hash','actor_kind','after_values','response_snapshot'] as $f) $t->text($f);
            $t->integer('actor_user_id')->nullable(); $t->text('before_values')->nullable(); $t->dateTime('performed_at');
            $t->unique('request_id'); $t->unique(['incomplete_case_id','revision']);
        });
        DB::table('result_statuses')->insert([['result_status_id'=>4,'status_code'=>'incomplete','status_name'=>'Incomplete','is_active'=>1], ['result_status_id'=>5,'status_code'=>'pending_approval','status_name'=>'Pending','is_active'=>1]]);
        DB::table('registration_statuses')->insert([['registration_status_id'=>4,'status_code'=>'completed'], ['registration_status_id'=>5,'status_code'=>'withdrawn']]);
    }
    private static function schema(): void
    {
        // Test-only projection of every column queried by these real production paths.
        $tables = [
            'account_statuses' => ['account_status_id', ['status_code']],
            'users' => ['user_id', ['username'], ['account_status_id', 'employee_id', 'student_id']],
            'roles' => ['role_id', ['role_code'], [], ['is_active']],
            'permissions' => ['permission_id', ['permission_code'], [], ['is_active']],
            'user_roles' => ['user_role_id', [], ['user_id', 'role_id'], ['is_active']],
            'role_permissions' => ['role_permission_id', [], ['role_id', 'permission_id']],
            'organizational_units' => ['organizational_unit_id', ['unit_code']],
            'user_access_scopes' => ['user_access_scope_id', ['scope_type'], ['user_id', 'scope_id'], ['is_active']],
            'colleges' => ['college_id', ['college_name']],
            'departments' => ['department_id', ['department_name'], ['college_id']],
            'academic_programs' => ['academic_program_id', ['program_name'], ['department_id']],
            'academic_levels' => ['academic_level_id', ['level_name']],
            'academic_years' => ['academic_year_id', ['year_name', 'start_date', 'end_date']],
            'semesters' => ['semester_id', ['semester_name'], ['semester_order']],
            'students' => ['student_id', ['student_number', 'first_name', 'last_name'], ['academic_program_id', 'current_academic_level_id']],
            'courses' => ['course_id', ['course_code', 'course_name'], ['credit_hours', 'theoretical_hours', 'practical_hours']],
            'course_offerings' => ['course_offering_id', ['status'], ['course_id', 'academic_year_id', 'semester_id', 'department_id', 'academic_program_id', 'faculty_member_id']],
            'registration_statuses' => ['registration_status_id', ['status_code', 'status_name'], [], ['is_active']],
            'result_statuses' => ['result_status_id', ['status_code', 'status_name'], [], ['is_active']],
            'student_course_registrations' => ['student_course_registration_id', [], ['student_id', 'course_offering_id', 'registration_status_id', 'result_status_id']],
            'grade_components' => ['grade_component_id', ['component_name', 'component_type'], ['course_offering_id'], ['is_required'], ['max_mark']],
            'student_grade_components' => ['student_grade_component_id', ['grade_status', 'notes', 'entered_at'], ['student_course_registration_id', 'grade_component_id', 'entered_by_user_id'], [], ['mark']],
            'grade_audit_logs' => ['grade_audit_log_id', ['change_reason', 'changed_at'], ['student_grade_component_id', 'changed_by_user_id'], [], ['old_mark', 'new_mark']],
            'grade_part_approvals' => ['grade_part_approval_id', ['component_type', 'status', 'submitted_at', 'reviewed_at', 'review_notes'], ['course_offering_id', 'submission_version', 'submitted_by_user_id', 'reviewed_by_user_id']],
            'grade_part_approval_events' => ['grade_part_approval_event_id', ['action', 'old_values', 'new_values', 'performed_at'], ['grade_part_approval_id', 'submission_version', 'performed_by_user_id']],
            'approval_statuses' => ['approval_status_id', ['status_code'], [], ['is_active']],
            'grade_approvals' => ['grade_approval_id', ['submitted_at', 'approval_date', 'approval_role', 'approval_notes'], ['course_offering_id', 'approval_status_id', 'submitted_by_user_id', 'approved_by_user_id']],
            'student_course_results' => ['student_course_result_id', ['calculated_at'], ['student_course_registration_id', 'result_status_id', 'calculated_by_user_id'], ['is_deprived'], ['theoretical_total', 'practical_total', 'coursework_total', 'final_mark']],
            'grading_policies' => ['grading_policy_id', ['policy_name'], [], ['is_default', 'is_active'], ['theoretical_max_mark', 'practical_max_mark', 'minimum_theoretical_mark', 'minimum_practical_mark', 'minimum_final_mark', 'absence_deprivation_percentage']],
            'employees' => ['employee_id', ['first_name', 'last_name']],
            'faculty_members' => ['faculty_member_id', [], ['employee_id'], ['is_active']],
            'course_offering_instructors' => ['course_offering_instructor_id', ['instructor_role'], ['course_offering_id', 'faculty_member_id', 'current_slot'], ['is_active', 'is_primary']],
            'program_courses' => ['program_course_id', [], ['academic_program_id', 'course_id', 'requirement_group_id'], ['is_active']],
            'supplementary_exam_materializations' => ['supplementary_exam_materialization_id', [], ['student_course_registration_id']],
            'supplementary_exam_periods' => ['supplementary_exam_period_id', ['status']],
            'supplementary_exam_offerings' => ['supplementary_exam_offering_id', [], ['supplementary_exam_period_id']],
            'supplementary_exam_registrations' => ['supplementary_exam_registration_id', ['status'], ['student_course_registration_id', 'supplementary_exam_offering_id', 'current_slot']],
        ];
        foreach ($tables as $name => $definition) {
            Schema::create($name, function (Blueprint $t) use ($definition, $name): void {
                $t->increments($definition[0]);
                foreach ($definition[1] as $column) $t->text($column)->nullable();
                foreach ($definition[2] ?? [] as $column) $t->integer($column)->nullable();
                foreach ($definition[3] ?? [] as $column) $t->boolean($column)->default($column !== 'is_deprived');
                foreach ($definition[4] ?? [] as $column) $t->decimal($column, 8, 2)->nullable();
                $t->timestamps();
                if ($name === 'students') $t->softDeletes();
            });
        }
        Schema::table('grade_part_approvals', fn (Blueprint $t) => $t->unique(['course_offering_id', 'component_type'], 'uq_fixture_grade_part'));
        Schema::table('student_grade_components', fn (Blueprint $t) => $t->unique(['student_course_registration_id', 'grade_component_id'], 'uq_fixture_grade_component'));
    }

    private static function seedManualGradeFixture(): void
    {
        DB::table('account_statuses')->insert(['account_status_id' => 1, 'status_code' => 'active']);
        DB::table('users')->insert(['user_id' => 1, 'username' => 'officer', 'account_status_id' => 1]);
        DB::table('roles')->insert(['role_id' => 1, 'role_code' => 'exam_officer', 'is_active' => 1]);
        DB::table('user_roles')->insert(['user_id' => 1, 'role_id' => 1, 'is_active' => 1]);
        foreach (['exams.manage', 'grades.manage', 'students.view', 'grades.view'] as $i => $code) {
            DB::table('permissions')->insert(['permission_id' => $i + 1, 'permission_code' => $code, 'is_active' => 1]);
            DB::table('role_permissions')->insert(['role_id' => 1, 'permission_id' => $i + 1]);
        }
        DB::table('organizational_units')->insert(['organizational_unit_id' => 1, 'unit_code' => 'PRES']);
        DB::table('user_access_scopes')->insert(['user_id' => 1, 'scope_type' => 'university', 'scope_id' => 1, 'is_active' => 1]);
        DB::table('colleges')->insert(['college_id' => 1, 'college_name' => 'College']);
        DB::table('departments')->insert(['department_id' => 1, 'college_id' => 1]);
        DB::table('academic_programs')->insert(['academic_program_id' => 1, 'department_id' => 1, 'program_name' => 'Program']);
        DB::table('academic_levels')->insert(['academic_level_id' => 1, 'level_name' => 'First']);
        DB::table('academic_years')->insert(['academic_year_id' => 1, 'year_name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        DB::table('semesters')->insert(['semester_id' => 1, 'semester_name' => 'First', 'semester_order' => 1]);
        foreach ([1 => 'registered', 2 => 'dropped', 3 => 'cancelled'] as $id => $code) DB::table('registration_statuses')->insert(['registration_status_id' => $id, 'status_code' => $code]);
        foreach ([1 => 'passed', 2 => 'failed', 3 => 'deprived'] as $id => $code) DB::table('result_statuses')->insert(['result_status_id' => $id, 'status_code' => $code, 'is_active' => 1]);
        DB::table('approval_statuses')->insert(['approval_status_id' => 1, 'status_code' => 'approved', 'is_active' => 1]);
        DB::table('courses')->insert(['course_id' => 1, 'course_code' => 'C1', 'course_name' => 'Course', 'credit_hours' => 3, 'theoretical_hours' => 2, 'practical_hours' => 1]);
        DB::table('course_offerings')->insert(['course_offering_id' => 1, 'course_id' => 1, 'academic_year_id' => 1, 'semester_id' => 1, 'academic_program_id' => 1, 'department_id' => 1, 'status' => 'open']);
        foreach ([1, 2] as $id) {
            DB::table('students')->insert(['student_id' => $id, 'student_number' => 'S'.$id, 'first_name' => 'Student', 'last_name' => (string) $id, 'academic_program_id' => 1, 'current_academic_level_id' => 1]);
            DB::table('student_course_registrations')->insert(['student_course_registration_id' => $id, 'student_id' => $id, 'course_offering_id' => 1, 'registration_status_id' => 1]);
        }
        DB::table('grade_components')->insert([
            ['grade_component_id' => 1, 'course_offering_id' => 1, 'component_name' => 'Theory', 'component_type' => 'theoretical', 'max_mark' => 60, 'is_required' => 1],
            ['grade_component_id' => 2, 'course_offering_id' => 1, 'component_name' => 'Practice', 'component_type' => 'practical', 'max_mark' => 40, 'is_required' => 1],
        ]);
        DB::table('grading_policies')->insert(['grading_policy_id' => 1, 'policy_name' => 'Canonical', 'theoretical_max_mark' => 60, 'practical_max_mark' => 40, 'minimum_theoretical_mark' => 15, 'minimum_practical_mark' => 10, 'minimum_final_mark' => 50, 'is_default' => 1, 'is_active' => 1]);
    }
}
