<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

final class RegistrationModificationWorkflow
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_SUPERSEDED = 'superseded';

    public const CURRENT_SLOT = 1;

    public const OPERATION_KEEP = 'keep';
    public const OPERATION_REMOVE = 'remove';
    public const OPERATION_ADD = 'add';

    public const EVENT_DRAFT_CREATED = 'draft_created';
    public const EVENT_BASELINE_SNAPSHOTTED = 'baseline_snapshotted';
    public const EVENT_ITEM_MARKED_REMOVE = 'item_marked_remove';
    public const EVENT_ITEM_RESTORED_KEEP = 'item_restored_keep';
    public const EVENT_ITEM_ADDED = 'item_added';
    public const EVENT_ITEM_REMOVED = 'item_removed';
    public const EVENT_SUBMITTED = 'submitted';
    public const EVENT_RESUBMITTED = 'resubmitted';
    public const EVENT_RETURNED = 'returned';
    public const EVENT_APPROVED = 'approved';
    public const EVENT_MATERIALIZED = 'materialized';
    public const EVENT_EXPIRED = 'expired_deadline';
    public const EVENT_SUPERSEDED = 'superseded_baseline_changed';

    public static function schemaReady(): bool
    {
        return Schema::hasTable('student_registration_modification_requests')
            && Schema::hasTable('student_registration_modification_items')
            && Schema::hasTable('student_registration_modification_events')
            && Schema::hasColumns('student_registration_modification_requests', [
                'student_registration_modification_request_id',
                'initial_registration_request_id',
                'student_id',
                'academic_year_id',
                'semester_id',
                'status',
                'submission_version',
                'current_slot',
                'student_notes',
                'advisor_user_id',
                'advisor_notes',
                'first_submitted_at',
                'last_submitted_at',
                'reviewed_at',
                'approved_at',
                'expired_at',
                'superseded_at',
                'materialized_at',
                'registered_hours_before_approval',
                'removed_hours_at_approval',
                'added_hours_at_approval',
                'projected_hours_at_approval',
                'max_allowed_hours_at_approval',
                'remaining_hours_after_approval',
            ])
            && Schema::hasColumns('student_registration_modification_items', [
                'student_registration_modification_item_id',
                'student_registration_modification_request_id',
                'operation',
                'course_offering_id',
                'source_student_course_registration_id',
                'materialized_student_course_registration_id',
            ])
            && Schema::hasColumns('student_registration_modification_events', [
                'student_registration_modification_event_id',
                'student_registration_modification_request_id',
                'event_type',
                'actor_user_id',
                'from_status',
                'to_status',
                'submission_version',
                'notes',
                'created_at',
            ]);
    }

    /** @return list<string> */
    public static function unresolvedStatuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_RETURNED];
    }
}
