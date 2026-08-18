<?php

namespace App\Support;

final class ExceptionalOpeningWorkflow
{
    public const PERMISSION_VIEW = 'course_offerings.exceptional_open.view';

    public const PERMISSION_REQUEST = 'course_offerings.exceptional_open.request';

    public const PERMISSION_REVIEW_SCIENTIFIC = 'course_offerings.exceptional_open.review_scientific';

    public const PERMISSION_REVIEW_ADMINISTRATIVE = 'course_offerings.exceptional_open.review_administrative';

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

    public const EVENT_SUPERSEDED = 'superseded';

    public const EVENT_MATERIALIZED = 'materialized';

    public const EVENT_SUPERSEDED_OFFERING_OPENED_NORMALLY = 'superseded_offering_opened_normally';

    public const EVENT_SUPERSEDED_NORMAL_OPENING_AVAILABLE = 'superseded_normal_opening_available';

    public const EVENT_SUPERSEDED_IDENTITY_STALE = 'superseded_identity_stale';

    public const SUPERSEDE_PRIOR_MATERIALIZATION = 'prior_materialization_consumed';

    public const SUPERSEDE_OFFERING_OPENED_NORMALLY = 'offering_opened_normally';

    public const SUPERSEDE_NORMAL_OPENING_AVAILABLE = 'normal_opening_available';

    public const SUPERSEDE_IDENTITY_STALE = 'identity_changed';
}
