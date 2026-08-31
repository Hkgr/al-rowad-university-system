<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 student semester registration lifecycle.
 *
 * Canonical lock order for every registration mutation that shares
 * lifecycle status (advisor materialization, self-drop, withdrawal
 * submit/return/resubmit/approve, and dropped reactivation):
 *
 *   1. students (student_id)
 *   2. course_offerings (course_offering_id, ascending when several)
 *   3. student_course_registrations (student_course_registration_id, ascending)
 *   4. current student_registration_withdrawal_requests, when present
 *
 * Initial StudentRegistrationRequest approval additionally locks that
 * request row first because it is a distinct workflow-root table and is
 * never locked by drop or withdrawal. Shared resources then follow 1–3.
 *
 * Registration does not reserve or release seats.
 * Legacy CourseOffering capacity fields are not registration policy and are
 * not mutated by create, reactivation, drop, or withdrawal transitions.
 */
final class RegistrationLifecycle
{
    public const PERMISSION_VIEW = 'registration_withdrawals.view';

    public const PERMISSION_REVIEW = 'registration_withdrawals.review';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SUPERSEDED = 'superseded';

    public const CURRENT_SLOT = 1;

    public const EVENT_SUBMITTED = 'withdrawal_submitted';

    public const EVENT_RETURNED = 'withdrawal_returned';

    public const EVENT_RESUBMITTED = 'withdrawal_resubmitted';

    public const EVENT_APPROVED = 'withdrawal_approved';

    public const EVENT_MATERIALIZED = 'withdrawal_materialized';

    public const EVENT_STALE = 'withdrawal_stale';

    public const REASON_MIN = 1;

    public const RETURN_NOTES_MIN = 8;

    public const REASON_MAX = 2000;

    public static function schemaReady(): bool
    {
        return Schema::hasTable('student_registration_withdrawal_requests')
            && Schema::hasTable('student_registration_withdrawal_events');
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
}
