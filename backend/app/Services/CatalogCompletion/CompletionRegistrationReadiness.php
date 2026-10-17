<?php

namespace App\Services\CatalogCompletion;

use App\Models\StudentCourseRegistration;
use App\Services\GradeService;
use App\Support\IncompleteOutcomeGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/** Completion only. Never changes scopeCurrent(), stored statuses or academic evidence. */
final class CompletionRegistrationReadiness
{
    private const BATCH = 100;
    public function __construct(private GradeService $grades) {}

    /** Authorized/scoped callers pass at most 50 explicit student IDs. Bounded registration reads. */
    public function forStudents(array $studentIds): array
    {
        if (count($studentIds) > 50) throw new \InvalidArgumentException('Completion readiness accepts at most 50 students.');
        $reports = [];
        foreach ($studentIds as $id) $reports[(int)$id] = ['historical_settled_registrations'=>0,'blocking_registrations'=>0,'blocking_reasons'=>[]];
        if (!$studentIds) return $reports;
        $lifecycleReady = IncompleteOutcomeGuard::ready();
        $canLocateCases = Schema::hasColumns(IncompleteOutcomeGuard::CASES,['student_course_registration_id']);
        $query = StudentCourseRegistration::whereIn('student_id',$studentIds)->where(function (Builder $q) use ($canLocateCases) {
            $q->current()->orWhereHas('studentCourseResult.resultStatus',fn ($s) => $s->where('status_code','incomplete'));
            // Completed registrations may also own an unresolved/protected lifecycle.
            if ($canLocateCases) $q->orWhereHas('incompleteCase');
        });
        $query->with(['registrationStatus','resultStatus','studentCourseResult.resultStatus','courseOffering',
            // Laravel eager group-limit selects ONE authoritative latest row per offering, not all history.
            'courseOffering.gradeApprovals'=>fn ($q) => $q->orderByDesc('grade_approval_id')->limit(1),
            'courseOffering.gradeApprovals.approvalStatus'])
            ->chunkById(self::BATCH,function ($rows) use (&$reports,$lifecycleReady) {
                $evidence = $lifecycleReady ? IncompleteOutcomeGuard::readPreservedOutcomes($rows) : [];
                foreach ($rows as $registration) {
                    $reason = $this->reason($registration,$lifecycleReady,$evidence[$registration->getKey()] ?? null);
                    $report = &$reports[(int)$registration->student_id];
                    if ($reason === null) $report['historical_settled_registrations']++;
                    else {
                        $report['blocking_registrations']++;
                        $report['blocking_reasons'][$reason] = ($report['blocking_reasons'][$reason] ?? 0)+1;
                    }
                    unset($report);
                }
            },'student_course_registration_id');
        foreach ($reports as &$report) ksort($report['blocking_reasons']); unset($report);
        return $reports;
    }

    private function reason(StudentCourseRegistration $r, bool $lifecycleReady, ?array $evidence): ?string
    {
        if (!in_array($r->registrationStatus?->status_code,['registered','completed'],true)) return 'unsupported_registration_state';
        if (!$r->courseOffering) return 'offering_missing';
        if (strtolower((string)$r->courseOffering->status) !== 'closed') return 'offering_not_closed';
        if (!$r->studentCourseResult) return 'result_missing';
        if (!$this->grades->isOfficiallyVisibleAttempt($r)) return 'authoritative_approval_not_approved';
        if (!$lifecycleReady) return 'incomplete_schema_unavailable';
        if ($evidence['reason'] ?? null) return $evidence['reason'];
        if (($evidence['state'] ?? null) === 'active') return 'incomplete_active';
        if (($evidence['state'] ?? null) !== null && !($evidence['preserved'] ?? false)) return 'incomplete_outcome_conflict';
        if ($r->studentCourseResult->resultStatus?->status_code === 'incomplete') return 'legacy_incomplete_unsettled';
        if (!in_array($r->studentCourseResult->resultStatus?->status_code,['passed','failed','deprived'],true)) return 'outcome_not_settled';
        $status = $this->grades->officialAttemptResultStatus($r);
        if (!in_array($status,['passed','failed','deprived'],true)) return 'outcome_not_settled';
        if ($status === 'passed' && $r->studentCourseResult->final_mark === null) return 'passed_outcome_missing_mark';
        // F/Z, including documented expired I, do NOT require invented marks/components.
        return null;
    }
}
