<?php

namespace App\Support;

final class TeachingAssignmentWorkflow
{
    public const PERMISSION_VIEW = 'teaching_assignments.view';

    public const PERMISSION_MANAGE = 'teaching_assignments.manage';

    public const PERMISSION_REVIEW_SCIENTIFIC = 'teaching_assignments.review_scientific';

    public const PERMISSION_REVIEW_ADMINISTRATIVE = 'teaching_assignments.review_administrative';

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

    public const EVENT_EFFECTIVE_CREATED = 'effective_assignment_created';

    public const EVENT_EFFECTIVE_CHANGED = 'effective_assignment_changed';
}
