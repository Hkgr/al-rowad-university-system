<?php

namespace App\Support;

final class CourseOfferingClosureWorkflow
{
    public const ROLE_DEAN = 'dean';

    public const PERMISSION_VIEW = 'course_offerings.closure.view';

    public const PERMISSION_REQUEST = 'course_offerings.closure.request';

    public const PERMISSION_REVIEW_SCIENTIFIC = 'course_offerings.closure.review_scientific';

    public const PERMISSION_REVIEW_ADMINISTRATIVE = 'course_offerings.closure.review_administrative';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SUPERSEDED = 'superseded';

    public const AUTHORITY_SCIENTIFIC = 'scientific';

    public const AUTHORITY_ADMINISTRATIVE = 'administrative';

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_RETURNED = 'returned';

    public const EVENT_SUBMITTED = 'submitted';

    public const EVENT_RESUBMITTED = 'resubmitted';

    public const EVENT_SCIENTIFIC_APPROVED = 'scientific_approved';

    public const EVENT_SCIENTIFIC_RETURNED = 'scientific_returned';

    public const EVENT_ADMINISTRATIVE_APPROVED = 'administrative_approved';

    public const EVENT_ADMINISTRATIVE_RETURNED = 'administrative_returned';

    public const EVENT_SUPERSEDED_IDENTITY_CHANGED = 'superseded_identity_changed';

    public const EVENT_SUPERSEDED_PRIOR_MATERIALIZATION = 'superseded_prior_materialization';

    public const EVENT_MATERIALIZED = 'materialized';

    public const SUPERSEDE_IDENTITY_CHANGED = 'identity_changed';

    public const SUPERSEDE_PRIOR_MATERIALIZATION = 'prior_materialization_consumed';
}
