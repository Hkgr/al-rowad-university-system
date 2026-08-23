<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SupplementaryExamMaterializationGovernance
{
    public const MATERIALIZE = 'supplementary_exams.results.materialize';

    public const SOURCE_PERIOD_STATUS = 'results_published';

    public const TERMINAL_PERIOD_STATUS = 'results_materialized';

    public const TABLE_COMMENT = 'owned:supplementary-exam-materialization-phase6';

    public static function schemaReady(): bool
    {
        try {
            $required = [
                'supplementary_exam_periods' => [
                    'supplementary_exam_period_id', 'status', 'updated_at',
                ],
                'supplementary_exam_period_events' => [
                    'supplementary_exam_period_event_id', 'supplementary_exam_period_id',
                    'event_type', 'from_status', 'to_status', 'actor_user_id', 'notes', 'created_at',
                ],
                'supplementary_exam_offerings' => [
                    'supplementary_exam_offering_id', 'supplementary_exam_period_id',
                    'academic_program_id', 'course_id',
                ],
                'supplementary_exam_offering_sources' => [
                    'supplementary_exam_offering_source_id', 'supplementary_exam_offering_id',
                    'course_offering_id',
                ],
                'supplementary_exam_registrations' => [
                    'supplementary_exam_registration_id', 'supplementary_exam_offering_id',
                    'student_id', 'student_course_registration_id', 'status', 'current_slot',
                    'eligibility_reason',
                ],
                'supplementary_exam_grade_results' => [
                    'supplementary_exam_grade_result_id', 'supplementary_exam_registration_id',
                    'supplementary_exam_offering_id', 'student_course_registration_id',
                    'student_id', 'theoretical_mark', 'status', 'submission_version',
                    'published_at', 'updated_at',
                ],
                'supplementary_exam_grade_events' => [
                    'supplementary_exam_grade_event_id', 'supplementary_exam_grade_result_id',
                    'supplementary_exam_grade_submission_id', 'event_type', 'from_status',
                    'to_status', 'submission_version', 'theoretical_mark', 'actor_user_id',
                    'notes', 'created_at',
                ],
                'supplementary_exam_grade_submissions' => [
                    'supplementary_exam_grade_submission_id', 'supplementary_exam_offering_id',
                    'submission_version', 'status', 'published_at', 'updated_at',
                ],
                'student_course_registrations' => [
                    'student_course_registration_id', 'student_id', 'course_offering_id',
                    'registration_status_id', 'result_status_id', 'updated_at',
                ],
                'student_course_results' => [
                    'student_course_result_id', 'student_course_registration_id',
                    'theoretical_total', 'practical_total', 'coursework_total', 'final_mark',
                    'result_status_id', 'is_deprived', 'calculated_at',
                    'calculated_by_user_id', 'updated_at',
                ],
                'course_offerings' => [
                    'course_offering_id', 'course_id', 'academic_program_id',
                ],
                'grade_approvals' => [
                    'grade_approval_id', 'course_offering_id', 'approval_status_id', 'updated_at',
                ],
                'approval_statuses' => [
                    'approval_status_id', 'status_code', 'is_active',
                ],
                'grade_components' => [
                    'grade_component_id', 'course_offering_id', 'component_type',
                    'max_mark', 'is_required', 'updated_at',
                ],
                'student_grade_components' => [
                    'student_grade_component_id', 'student_course_registration_id',
                    'grade_component_id', 'mark', 'grade_status', 'updated_at',
                ],
                'grading_policies' => [
                    'grading_policy_id', 'theoretical_max_mark', 'practical_max_mark',
                    'minimum_theoretical_mark', 'minimum_practical_mark',
                    'minimum_final_mark', 'is_default', 'is_active',
                ],
                'registration_statuses' => [
                    'registration_status_id', 'status_code',
                ],
                'result_statuses' => [
                    'result_status_id', 'status_code', 'is_active',
                ],
                'students' => [
                    'student_id',
                ],
                'users' => [
                    'user_id',
                ],
                'roles' => [
                    'role_id', 'role_code', 'is_active',
                ],
                'permissions' => [
                    'permission_id', 'module_id', 'permission_code', 'is_active',
                ],
                'role_permissions' => [
                    'role_id', 'permission_id',
                ],
                'user_roles' => [
                    'user_id', 'role_id', 'is_active',
                ],
                'system_modules' => [
                    'module_id', 'module_code', 'is_active',
                ],
                'user_access_scopes' => [
                    'user_id', 'scope_type', 'scope_id', 'is_active',
                ],
                'academic_programs' => [
                    'academic_program_id', 'department_id',
                ],
                'departments' => [
                    'department_id', 'college_id',
                ],
                'colleges' => [
                    'college_id',
                ],
                'organizational_units' => [
                    'organizational_unit_id', 'unit_code',
                ],
                'supplementary_exam_materializations' => [
                    'supplementary_exam_materialization_id',
                    'supplementary_exam_registration_id',
                    'supplementary_exam_offering_id',
                    'supplementary_exam_grade_result_id',
                    'supplementary_exam_grade_event_id',
                    'supplementary_exam_grade_submission_id',
                    'source_submission_version',
                    'student_course_registration_id',
                    'student_course_result_id',
                    'student_id',
                    'grading_policy_id',
                    'grade_approval_id',
                    'preserved_registration_status_id',
                    'source_theoretical_mark',
                    'practical_components_snapshot',
                    'before_theoretical_components_snapshot',
                    'after_theoretical_components_snapshot',
                    'source_registration_updated_at',
                    'source_result_published_at',
                    'source_submission_published_at',
                    'source_result_updated_at',
                    'source_submission_updated_at',
                    'grade_approval_updated_at',
                    'before_theoretical_total',
                    'before_practical_total',
                    'before_coursework_total',
                    'before_final_mark',
                    'before_result_status_id',
                    'before_registration_result_status_id',
                    'before_is_deprived',
                    'before_calculated_at',
                    'before_result_announced_at',
                    'before_calculated_by_user_id',
                    'before_result_updated_at',
                    'before_registration_updated_at',
                    'after_theoretical_total',
                    'after_practical_total',
                    'after_coursework_total',
                    'after_final_mark',
                    'after_result_status_id',
                    'after_registration_result_status_id',
                    'after_is_deprived',
                    'after_calculated_at',
                    'after_result_announced_at',
                    'after_calculated_by_user_id',
                    'after_result_updated_at',
                    'after_registration_updated_at',
                    'materialized_by_user_id',
                    'materialized_at',
                    'created_at',
                ],
                'supplementary_exam_materialization_events' => [
                    'supplementary_exam_materialization_event_id',
                    'supplementary_exam_materialization_id',
                    'supplementary_exam_offering_id',
                    'supplementary_exam_registration_id',
                    'event_type',
                    'source_submission_version',
                    'actor_user_id',
                    'created_at',
                ],
            ];

            foreach ($required as $table => $columns) {
                if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                    return false;
                }
            }

            return self::permissionReady();
        } catch (Throwable) {
            return false;
        }
    }

    public static function materializationTableAvailable(): bool
    {
        try {
            return Schema::hasTable('supplementary_exam_materializations');
        } catch (Throwable) {
            return false;
        }
    }

    public static function resultAnnouncedAtAvailable(): bool
    {
        try {
            return Schema::hasColumn('student_course_results', 'result_announced_at');
        } catch (Throwable) {
            return false;
        }
    }

    private static function permissionReady(): bool
    {
        $permissionCount = DB::table('permissions as p')
            ->join('system_modules as m', 'm.module_id', '=', 'p.module_id')
            ->where('p.permission_code', self::MATERIALIZE)
            ->where('p.is_active', true)
            ->where('m.module_code', 'exams')
            ->where('m.is_active', true)
            ->count();

        if ($permissionCount !== 1) {
            return false;
        }

        $allMappings = DB::table('role_permissions as rp')
            ->join('permissions as p', 'p.permission_id', '=', 'rp.permission_id')
            ->where('p.permission_code', self::MATERIALIZE)
            ->count();

        $examOfficerMappings = DB::table('role_permissions as rp')
            ->join('permissions as p', 'p.permission_id', '=', 'rp.permission_id')
            ->join('roles as r', 'r.role_id', '=', 'rp.role_id')
            ->where('p.permission_code', self::MATERIALIZE)
            ->where('r.role_code', 'exam_officer')
            ->where('r.is_active', true)
            ->count();

        return $allMappings === 1 && $examOfficerMappings === 1;
    }
}
