<?php

namespace App\Support;

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SupplementaryExamEligibilityGovernance
{
    public const PERMISSION_VIEW = 'supplementary_exams.eligibility.view';
    public const PERMISSION_SELF = 'supplementary_exams.deferrals.self';

    public static function schemaReady(): bool
    {
        try {
            if (! SupplementaryExamOfferingGovernance::schemaReady()) return false;
            $schema = Schema::connection((string) config('database.default'));
            if (! $schema instanceof Builder || ! method_exists($schema, 'getIndexes') || ! method_exists($schema, 'getForeignKeys')) return false;
            foreach (['supplementary_exam_theoretical_deferrals','supplementary_exam_theoretical_deferral_events'] as $table) {
                if (! $schema->hasTable($table)) return false;
            }
            $indexes = $schema->getIndexes('supplementary_exam_theoretical_deferrals');
            $unique = collect($indexes)->filter(fn ($i) => ! empty($i['unique']))->map(fn ($i) => array_values($i['columns'] ?? []));
            return $unique->contains(['supplementary_exam_offering_id','student_course_registration_id'])
                && $unique->contains(['student_course_registration_id','current_slot']);
        } catch (Throwable) { return false; }
    }
}
