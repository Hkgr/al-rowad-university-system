<?php

namespace App\Exceptions;

use Exception;

class AcademicRecordException extends Exception
{
    public const ACADEMIC_TERM_WORKFLOW_REQUIRED = 'academic_term_workflow_required';

    public const ACADEMIC_TERM_FINALIZED = 'academic_term_finalized';

    public const ACADEMIC_TERM_IDENTITY_CONFLICT = 'academic_term_identity_conflict';

    public const ACADEMIC_LEVEL_PROGRESSION_WORKFLOW_REQUIRED = 'academic_level_progression_workflow_required';

    public const ACADEMIC_PROGRESSION_NOT_READY = 'academic_progression_not_ready';

    public const ACADEMIC_PROGRESSION_STALE = 'academic_progression_stale';

    public const ACADEMIC_PROGRESSION_ALREADY_MATERIALIZED = 'academic_progression_already_materialized';

    public const ACADEMIC_RESULTS_NOT_FINAL = 'academic_results_not_final';

    public const ACADEMIC_PROGRESSION_REVIEW_FORBIDDEN = 'academic_progression_review_forbidden';

    public const GRADUATION_NOT_ELIGIBLE = 'graduation_not_eligible';

    public const GRADUATION_GPA_REQUIREMENT_NOT_MET = 'graduation_gpa_requirement_not_met';

    public const GRADUATION_RESULTS_NOT_FINAL = 'graduation_results_not_final';

    public const GRADUATION_DECISION_STALE = 'graduation_decision_stale';

    public const GRADUATION_ALREADY_MATERIALIZED = 'graduation_already_materialized';

    public const GRADUATION_REVIEW_FORBIDDEN = 'graduation_review_forbidden';

    public const GRADUATION_STATUS_NOT_CONFIGURED = 'graduation_status_not_configured';

    public const GRADUATION_DECISION_WORKFLOW_REQUIRED = 'graduation_decision_workflow_required';

    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 409,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    public static function academicTermWorkflowRequired(): self
    {
        $message = 'Academic term snapshots must be calculated and finalized through the academic-record workflow.';

        return new self($message, ['academic_term' => [$message]], 409, self::ACADEMIC_TERM_WORKFLOW_REQUIRED);
    }

    public static function academicTermFinalized(): self
    {
        $message = 'A finalized academic term snapshot is immutable through generic CRUD.';

        return new self($message, ['academic_term' => [$message]], 409, self::ACADEMIC_TERM_FINALIZED);
    }

    public static function academicTermIdentityConflict(): self
    {
        $message = 'An academic term snapshot already exists for this student, academic year, and semester.';

        return new self($message, ['academic_term' => [$message]], 409, self::ACADEMIC_TERM_IDENTITY_CONFLICT);
    }

    public static function academicLevelProgressionWorkflowRequired(): self
    {
        $message = 'A student current academic level may only change through the formal progression workflow.';

        return new self($message, ['current_academic_level_id' => [$message]], 409, self::ACADEMIC_LEVEL_PROGRESSION_WORKFLOW_REQUIRED);
    }

    public static function academicProgressionNotReady(): self
    {
        $message = 'This student is not ready for a formal academic progression decision.';

        return new self($message, ['progression' => [$message]], 409, self::ACADEMIC_PROGRESSION_NOT_READY);
    }

    public static function academicProgressionStale(): self
    {
        $message = 'This academic progression decision is stale because the student academic state has changed.';

        return new self($message, ['progression' => [$message]], 409, self::ACADEMIC_PROGRESSION_STALE);
    }

    public static function academicProgressionAlreadyMaterialized(): self
    {
        $message = 'This academic progression decision has already been materialized.';

        return new self($message, ['progression' => [$message]], 409, self::ACADEMIC_PROGRESSION_ALREADY_MATERIALIZED);
    }

    public static function academicResultsNotFinal(): self
    {
        $message = 'Formal academic record finalization cannot proceed while official academic results are incomplete or unfinalized.';

        return new self($message, ['academic_results' => [$message]], 409, self::ACADEMIC_RESULTS_NOT_FINAL);
    }

    public static function academicProgressionReviewForbidden(): self
    {
        $message = 'Only a registration officer with assigned academic progression permission may review progression decisions.';

        return new self($message, ['progression' => [$message]], 403, self::ACADEMIC_PROGRESSION_REVIEW_FORBIDDEN);
    }

    public static function graduationNotEligible(): self
    {
        $message = 'The student does not meet academic graduation requirements.';

        return new self($message, ['graduation' => [$message]], 409, self::GRADUATION_NOT_ELIGIBLE);
    }

    public static function graduationGpaRequirementNotMet(): self
    {
        $message = 'The student cumulative GPA does not meet the canonical graduation minimum.';

        return new self($message, ['graduation' => [$message]], 409, self::GRADUATION_GPA_REQUIREMENT_NOT_MET);
    }

    public static function graduationResultsNotFinal(): self
    {
        $message = 'Formal graduation cannot finalize while official academic results are incomplete or unfinalized.';

        return new self($message, ['graduation' => [$message]], 409, self::GRADUATION_RESULTS_NOT_FINAL);
    }

    public static function graduationDecisionStale(): self
    {
        $message = 'This graduation decision is stale because eligibility or student identity has changed.';

        return new self($message, ['graduation' => [$message]], 409, self::GRADUATION_DECISION_STALE);
    }

    public static function graduationAlreadyMaterialized(): self
    {
        $message = 'This graduation decision has already been materialized.';

        return new self($message, ['graduation' => [$message]], 409, self::GRADUATION_ALREADY_MATERIALIZED);
    }

    public static function graduationReviewForbidden(): self
    {
        $message = 'Only a registration officer with assigned graduation-decision permission may review graduation decisions.';

        return new self($message, ['graduation' => [$message]], 403, self::GRADUATION_REVIEW_FORBIDDEN);
    }

    public static function graduationStatusNotConfigured(): self
    {
        $message = 'The canonical graduated student status is not configured.';

        return new self($message, ['graduation' => [$message]], 409, self::GRADUATION_STATUS_NOT_CONFIGURED);
    }

    public static function graduationDecisionWorkflowRequired(): self
    {
        $message = 'A student graduated status may only be entered or left through the formal graduation decision workflow.';

        return new self($message, ['student_status' => [$message]], 409, self::GRADUATION_DECISION_WORKFLOW_REQUIRED);
    }
}
