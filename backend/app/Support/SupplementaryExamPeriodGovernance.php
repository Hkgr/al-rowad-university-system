<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

final class SupplementaryExamPeriodGovernance
{
    public const PERMISSION_VIEW = 'supplementary_exams.periods.view';

    public const PERMISSION_DECIDE = 'supplementary_exams.periods.decide';

    public const STATUS_LEGACY = 'legacy';

    public const STATUS_ANNOUNCED = 'announced';

    public const EVENT_ANNOUNCED = 'announced';

    /**
     * Documented for later phases. Phase 1 must not transition to these.
     *
     * @var list<string>
     */
    public const FUTURE_STATUSES = [
        'registration_open',
        'registration_closed',
        'in_progress',
        'results_processing',
        'locked',
    ];

    public static function schemaReady(): bool
    {
        return Schema::hasTable('supplementary_exam_periods')
            && Schema::hasTable('supplementary_exam_period_events')
            && Schema::hasColumn('supplementary_exam_periods', 'status')
            && Schema::hasColumn('supplementary_exam_periods', 'opened_by_user_id')
            && Schema::hasColumn('supplementary_exam_periods', 'opened_at')
            && Schema::hasColumn('supplementary_exam_periods', 'decision_note');
    }
}
