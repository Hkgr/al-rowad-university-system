<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 formal academic record, progression, and graduation.
 *
 * Canonical official academic attempt (reused, not redefined):
 *   GradeService::officialAcademicAttempts()
 *   — student_course_registrations that are academic attempts
 *     (registered/completed), have a student_course_result, and belong to a
 *     course offering whose latest grade_approvals row is approved.
 *
 * Canonical GPA:
 *   GradeService 4.0 scale, repeated courses = highest_attempt_only.
 *   Graduation minimum = GraduationGpaPolicy::MINIMUM_CUMULATIVE_GPA (2.0).
 *
 * Canonical lock order (compatible with grade finalization, which locks
 * CourseOffering then registrations, and with Phase 9 registration, which
 * locks Student then CourseOffering then registrations):
 *
 *   1. students (student_id)
 *   2. course_offerings involved for the student (course_offering_id ASC)
 *   3. student_course_registrations (student_course_registration_id ASC)
 *   4. student_academic_terms (academic_year_id, semester_id, id ASC)
 *   5. current progression or graduation decision
 *   6. event / materialization rows (inserts)
 *
 * Never lock registrations or terms before the related offerings when those
 * offerings must be locked — that order would deadlock with
 * GradeApprovalWorkflowService / GradeService.
 */
final class AcademicRecordWorkflow
{
    public const AUTHORITY_ROLE = 'registration_officer';

    public const GRADUATED_STATUS = 'graduated';

    public const PERMISSION_RECORDS_VIEW = 'academic_records.view';

    public const PERMISSION_RECORDS_FINALIZE = 'academic_records.finalize';

    public const PERMISSION_PROGRESSION_VIEW = 'academic_progression.view';

    public const PERMISSION_PROGRESSION_REVIEW = 'academic_progression.review';

    public const PERMISSION_GRADUATION_VIEW = 'graduation_decisions.view';

    public const PERMISSION_GRADUATION_REVIEW = 'graduation_decisions.review';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SUPERSEDED = 'superseded';

    public const CURRENT_SLOT = 1;

    public const RESULT_PROMOTED = 'promoted';

    public const RESULT_RETAINED = 'retained';

    public const RESULT_GRADUATED = 'graduated';

    public const CLASSIFICATION_READY_FOR_REVIEW = 'ready_for_review';

    public const CLASSIFICATION_BLOCKED_INCOMPLETE_RESULTS = 'blocked_incomplete_results';

    public const EVENT_PROGRESSION_SUBMITTED = 'progression_submitted';

    public const EVENT_PROGRESSION_RETURNED = 'progression_returned';

    public const EVENT_PROGRESSION_APPROVED = 'progression_approved';

    public const EVENT_PROGRESSION_MATERIALIZED = 'progression_materialized';

    public const EVENT_PROGRESSION_STALE = 'progression_stale';

    public const EVENT_GRADUATION_SUBMITTED = 'graduation_submitted';

    public const EVENT_GRADUATION_RETURNED = 'graduation_returned';

    public const EVENT_GRADUATION_APPROVED = 'graduation_approved';

    public const EVENT_GRADUATION_MATERIALIZED = 'graduation_materialized';

    public const EVENT_GRADUATION_STALE = 'graduation_stale';

    public const RETURN_NOTES_MIN = 8;

    public const RETURN_NOTES_MAX = 2000;

    public static function schemaReady(): bool
    {
        return Schema::hasTable('student_academic_terms')
            && Schema::hasColumn('student_academic_terms', 'is_finalized')
            && Schema::hasTable('student_progression_decisions')
            && Schema::hasTable('student_progression_events')
            && Schema::hasTable('student_graduation_decisions')
            && Schema::hasTable('student_graduation_events');
    }

    /**
     * @return list<string>
     */
    public static function workflowStatuses(): array
    {
        return [
            self::STATUS_SUBMITTED,
            self::STATUS_RETURNED,
            self::STATUS_APPROVED,
            self::STATUS_SUPERSEDED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function progressionResults(): array
    {
        return [
            self::RESULT_PROMOTED,
            self::RESULT_RETAINED,
        ];
    }
}
