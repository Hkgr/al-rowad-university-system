<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SupplementaryExamGradingGovernance
{
    public const VIEW = 'supplementary_exams.grades.view';
    public const ASSIGN = 'supplementary_exams.grades.assign';
    public const ENTER = 'supplementary_exams.grades.enter';
    public const REVIEW = 'supplementary_exams.grades.review';
    public const PUBLISH = 'supplementary_exams.grades.publish';
    public const PERIOD_STATUSES = ['registration_closed', 'grading_open', 'grading_submitted', 'results_approved', 'results_published'];
    public const RESULT_STATUSES = ['draft', 'submitted', 'returned', 'approved', 'published'];

    public static function schemaReady(): bool
    {
        try {
            if (! SupplementaryExamRegistrationGovernance::schemaReady()) return false;
            foreach (['supplementary_exam_grader_assignments', 'supplementary_exam_grade_results', 'supplementary_exam_grade_submissions', 'supplementary_exam_grade_events'] as $table) {
                if (! Schema::hasTable($table)) return false;
            }
            $columns = [
                'supplementary_exam_grader_assignments' => ['supplementary_exam_grader_assignment_id','supplementary_exam_offering_id','faculty_member_id','current_slot','assigned_by_user_id','assigned_at','ended_at'],
                'supplementary_exam_grade_results' => ['supplementary_exam_grade_result_id','supplementary_exam_registration_id','supplementary_exam_offering_id','student_course_registration_id','student_id','theoretical_mark','status','submission_version','published_at'],
                'supplementary_exam_grade_submissions' => ['supplementary_exam_grade_submission_id','supplementary_exam_offering_id','submission_version','status','submitted_by_user_id','submitted_at','reviewed_by_user_id','reviewed_at','review_reason','published_by_user_id','published_at'],
                'supplementary_exam_grade_events' => ['supplementary_exam_grade_event_id','supplementary_exam_grade_result_id','supplementary_exam_grade_submission_id','event_type','from_status','to_status','submission_version','theoretical_mark','actor_user_id','notes','created_at'],
            ];
            foreach ($columns as $table => $required) if (! Schema::hasColumns($table, $required)) return false;
            return DB::table('permissions')->whereIn('permission_code', [self::VIEW,self::ASSIGN,self::ENTER,self::REVIEW,self::PUBLISH])->where('is_active', true)->distinct()->count('permission_code') === 5;
        } catch (Throwable) { return false; }
    }
}
