<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

final class SemesterOfferingGovernance
{
    public const PERMISSION_VIEW = 'course_offerings.semester_governance.view';
    public const PERMISSION_MANAGE = 'course_offerings.semester_governance.manage';
    public const PERMISSION_REVIEW_SCIENTIFIC = 'course_offerings.semester_governance.review_scientific';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_APPROVED = 'approved';

    public const REVIEW_PENDING = 'pending';
    public const REVIEW_APPROVED = 'approved';
    public const REVIEW_RETURNED = 'returned';

    public const EVENT_PREPARED = 'prepared';
    public const EVENT_UPDATED = 'updated';
    public const EVENT_DESELECTED = 'deselected';
    public const EVENT_SUBMITTED = 'submitted';
    public const EVENT_RESUBMITTED = 'resubmitted';
    public const EVENT_RETURNED = 'scientific_returned';
    public const EVENT_APPROVED = 'scientific_approved';
    public const EVENT_MATERIALIZED = 'materialized';

    public static function schemaReady(): bool
    {
        return Schema::hasTable('semester_offering_requests')
            && Schema::hasTable('semester_offering_reviews')
            && Schema::hasTable('semester_offering_events')
            && Schema::hasColumn('semester_offering_requests', 'materialized_at');
    }
}
