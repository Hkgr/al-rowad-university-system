<?php

namespace App\Services;

use App\Exceptions\GradeException;
use App\Models\SupplementaryExamOffering;
use App\Models\SupplementaryExamPeriod;
use App\Models\User;
use App\Support\SupplementaryExamGradingGovernance as GradingGovernance;
use App\Support\SupplementaryExamMaterializationGovernance as MaterializationGovernance;
use App\Support\SupplementaryExamRegistrationGovernance as RegistrationGovernance;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only period reconciliation. All period data is loaded in bounded batch
 * queries; the service deliberately owns no write path.
 */
class SupplementaryExamReconciliationService
{
    public function __construct(private readonly DataScopeService $scope) {}

    public function reconcile(User $actor, SupplementaryExamPeriod $period): array
    {
        return DB::transaction(function () use ($actor, $period): array {
            $snapshotPeriod = SupplementaryExamPeriod::query()->findOrFail($period->getKey());

            return $this->reconcileSnapshot($actor, $snapshotPeriod);
        }, 3);
    }

    /** One repeatable-read snapshot; this method contains no write primitive. */
    private function reconcileSnapshot(User $actor, SupplementaryExamPeriod $period): array
    {
        $this->assertAuthorized($actor);
        if (! MaterializationGovernance::schemaReady()) {
            $this->fail(
                'The supplementary materialization schema is not ready.',
                'supplementary_reconciliation_schema_not_ready',
                503,
            );
        }

        $allOfferings = SupplementaryExamOffering::query()
            ->with(['academicProgram.department', 'course'])
            ->where('supplementary_exam_period_id', $period->getKey())
            ->orderBy('supplementary_exam_offering_id')
            ->get();
        $actualScopes = collect($this->scope->scopes($actor));
        $mutableProgramIds = $this->mutableProgramIds($allOfferings, $actualScopes);
        $offerings = $allOfferings
            ->filter(fn (SupplementaryExamOffering $offering): bool =>
                $mutableProgramIds->contains((int) $offering->academic_program_id)
            )
            ->values();

        if ($allOfferings->isNotEmpty() && $offerings->isEmpty()) {
            $this->fail(
                'The supplementary period is outside the Exam Officer mutation scope.',
                'supplementary_reconciliation_out_of_scope',
                403,
            );
        }

        $scopeComplete = $offerings->count() === $allOfferings->count();
        $offeringIds = $offerings->modelKeys();
        $periodStatus = (string) $period->status;
        $terminal = $periodStatus === MaterializationGovernance::TERMINAL_PERIOD_STATUS;
        $publicationRequired = in_array($periodStatus, [
            MaterializationGovernance::SOURCE_PERIOD_STATUS,
            MaterializationGovernance::TERMINAL_PERIOD_STATUS,
        ], true);
        $resultAnnouncedAtAvailable = MaterializationGovernance::resultAnnouncedAtAvailable();

        $registrations = $this->tableRows('supplementary_exam_registrations', 'supplementary_exam_offering_id', $offeringIds);
        $roster = $registrations
            ->where('status', 'registered')
            ->filter(fn (object $row): bool => (int) $row->current_slot === 1)
            ->values();
        $results = $this->tableRows('supplementary_exam_grade_results', 'supplementary_exam_offering_id', $offeringIds);
        $submissions = $this->tableRows('supplementary_exam_grade_submissions', 'supplementary_exam_offering_id', $offeringIds);
        $sources = $this->tableRows('supplementary_exam_offering_sources', 'supplementary_exam_offering_id', $offeringIds);

        $sourceResultIds = $results->pluck('supplementary_exam_grade_result_id')->map(fn ($id): int => (int) $id);
        $sourceEvents = $this->tableRows('supplementary_exam_grade_events', 'supplementary_exam_grade_result_id', $sourceResultIds);
        $targetRegistrationIds = $roster->pluck('student_course_registration_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $fixedRosterOccurrences = $targetRegistrationIds->isEmpty()
            ? collect()
            : DB::table('supplementary_exam_registrations as registration')
                ->join(
                    'supplementary_exam_offerings as offering',
                    'offering.supplementary_exam_offering_id',
                    '=',
                    'registration.supplementary_exam_offering_id',
                )
                ->join(
                    'supplementary_exam_periods as period',
                    'period.supplementary_exam_period_id',
                    '=',
                    'offering.supplementary_exam_period_id',
                )
                ->whereIn('registration.student_course_registration_id', $targetRegistrationIds)
                ->where('registration.status', 'registered')
                ->where('registration.current_slot', 1)
                ->whereIn('period.status', RegistrationGovernance::FIXED_ROSTER_PERIOD_STATUSES)
                ->orderBy('registration.supplementary_exam_registration_id')
                ->get([
                    'registration.supplementary_exam_registration_id',
                    'registration.student_course_registration_id',
                    'registration.supplementary_exam_offering_id',
                    'period.supplementary_exam_period_id',
                ]);

        $materializations = collect();
        if ($offeringIds !== [] || $targetRegistrationIds->isNotEmpty()) {
            $materializations = DB::table('supplementary_exam_materializations')
                ->where(function ($query) use ($offeringIds, $targetRegistrationIds): void {
                    if ($offeringIds !== []) {
                        $query->whereIn('supplementary_exam_offering_id', $offeringIds);
                    }
                    if ($targetRegistrationIds->isNotEmpty()) {
                        $method = $offeringIds === [] ? 'whereIn' : 'orWhereIn';
                        $query->{$method}('student_course_registration_id', $targetRegistrationIds);
                    }
                })
                ->orderBy('supplementary_exam_materialization_id')
                ->get();
        }
        $materializationIds = $materializations->pluck('supplementary_exam_materialization_id')->map(fn ($id): int => (int) $id);
        $materializationEvents = $this->tableRows(
            'supplementary_exam_materialization_events',
            'supplementary_exam_materialization_id',
            $materializationIds,
        );

        $targetRegistrations = $this->tableRows(
            'student_course_registrations',
            'student_course_registration_id',
            $targetRegistrationIds,
        );
        $targetResults = $this->tableRows(
            'student_course_results',
            'student_course_registration_id',
            $targetRegistrationIds,
        );
        $targetOfferingIds = $targetRegistrations->pluck('course_offering_id')
            ->concat($sources->pluck('course_offering_id'))
            ->map(fn ($id): int => (int) $id)->unique()->values();
        $targetOfferings = $this->tableRows('course_offerings', 'course_offering_id', $targetOfferingIds);
        $approvals = $this->tableRows('grade_approvals', 'course_offering_id', $targetOfferingIds);
        $components = $this->tableRows('grade_components', 'course_offering_id', $targetOfferingIds);
        $gradeRows = $this->tableRows(
            'student_grade_components',
            'student_course_registration_id',
            $targetRegistrationIds,
        );
        $approvalStatusIds = $approvals->pluck('approval_status_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $approvalStatuses = $this->tableRows('approval_statuses', 'approval_status_id', $approvalStatusIds)
            ->keyBy('approval_status_id');
        $registrationStatuses = $this->tableRows(
            'registration_statuses',
            'registration_status_id',
            $targetRegistrations->pluck('registration_status_id')->map(fn ($id): int => (int) $id)->unique()->values(),
        )->keyBy('registration_status_id');
        $resultStatuses = $this->tableRows(
            'result_statuses',
            'result_status_id',
            $targetResults->pluck('result_status_id')
                ->concat($materializations->pluck('before_result_status_id'))
                ->filter(fn ($id): bool => $id !== null)
                ->map(fn ($id): int => (int) $id)->unique()->values(),
        )->keyBy('result_status_id');
        $gradingPolicies = $this->tableRows(
            'grading_policies',
            'grading_policy_id',
            $materializations->pluck('grading_policy_id')->map(fn ($id): int => (int) $id)->unique()->values(),
        )->keyBy('grading_policy_id');
        $activePolicies = DB::table('grading_policies')
            ->where('is_active', true)
            ->orderBy('grading_policy_id')
            ->get();
        $defaultPolicies = $activePolicies->where('is_default', true)->values();
        $canonicalPolicy = $defaultPolicies->count() > 1
            ? null
            : ($defaultPolicies->first() ?? $activePolicies->first());
        $terminalEvents = DB::table('supplementary_exam_period_events')
            ->where('supplementary_exam_period_id', $period->getKey())
            ->where('event_type', 'results_materialized')
            ->orderBy('supplementary_exam_period_event_id')
            ->get();

        $registrationsByOffering = $registrations->groupBy('supplementary_exam_offering_id');
        $rosterByOffering = $roster->groupBy('supplementary_exam_offering_id');
        $resultsByOffering = $results->groupBy('supplementary_exam_offering_id');
        $resultsByRegistration = $results->groupBy('supplementary_exam_registration_id');
        $submissionsByOffering = $submissions->groupBy('supplementary_exam_offering_id');
        $eventsByResult = $sourceEvents->groupBy('supplementary_exam_grade_result_id');
        $sourcesByOffering = $sources->groupBy('supplementary_exam_offering_id');
        $materializationsByOffering = $materializations->groupBy('supplementary_exam_offering_id');
        $materializationsBySource = $materializations->groupBy('supplementary_exam_registration_id');
        $materializationsByTarget = $materializations->groupBy('student_course_registration_id');
        $materializationEventsByMaterialization = $materializationEvents->groupBy('supplementary_exam_materialization_id');
        $targetRegistrationsById = $targetRegistrations->keyBy('student_course_registration_id');
        $targetResultsByRegistration = $targetResults->groupBy('student_course_registration_id');
        $targetOfferingsById = $targetOfferings->keyBy('course_offering_id');
        $approvalsByOffering = $approvals->groupBy('course_offering_id');
        $componentsByOffering = $components->groupBy('course_offering_id');
        $gradeRowsByRegistration = $gradeRows->groupBy('student_course_registration_id');

        $issues = collect();
        $offeringsById = $offerings->keyBy('supplementary_exam_offering_id');
        foreach ($roster as $fixedRegistration) {
            $offering = $offeringsById->get((int) $fixedRegistration->supplementary_exam_offering_id);
            $targetRegistration = $targetRegistrationsById->get((int) $fixedRegistration->student_course_registration_id);
            $candidateTargetResults = $targetResultsByRegistration->get(
                (int) $fixedRegistration->student_course_registration_id,
                collect(),
            );
            $targetResult = $candidateTargetResults->first();
            $targetOffering = $targetOfferingsById->get((int) ($targetRegistration?->course_offering_id ?? 0));
            $registrationStatus = $registrationStatuses->get((int) ($targetRegistration?->registration_status_id ?? 0));
            $targetResultStatus = $resultStatuses->get((int) ($targetResult?->result_status_id ?? 0));
            $fixedMaterialization = $materializationsBySource
                ->get((int) $fixedRegistration->supplementary_exam_registration_id, collect())
                ->first();
            $eligibilityResultStatus = $fixedMaterialization
                ? $resultStatuses->get((int) $fixedMaterialization->before_result_status_id)
                : $targetResultStatus;
            $preconditionPolicy = $fixedMaterialization
                ? $gradingPolicies->get((int) $fixedMaterialization->grading_policy_id)
                : $canonicalPolicy;
            $latestApproval = $approvalsByOffering
                ->get((int) ($targetOffering?->course_offering_id ?? 0), collect())
                ->sortByDesc('grade_approval_id')->first();
            $approvalStatus = $latestApproval
                ? $approvalStatuses->get((int) $latestApproval->approval_status_id)
                : null;
            $requiredComponents = $componentsByOffering
                ->get((int) ($targetOffering?->course_offering_id ?? 0), collect())
                ->filter(fn (object $row): bool => (bool) $row->is_required)
                ->whereIn('component_type', ['theoretical', 'practical']);
            $theoreticalDefinitions = $requiredComponents->where('component_type', 'theoretical');
            $practicalDefinitions = $requiredComponents->where('component_type', 'practical');
            $sourceAllowed = $offering && $targetOffering && $sourcesByOffering
                ->get((int) $offering->supplementary_exam_offering_id, collect())
                ->pluck('course_offering_id')
                ->map(fn ($id): int => (int) $id)
                ->contains((int) $targetOffering->course_offering_id);
            $eligibilityValid = in_array(
                $fixedRegistration->eligibility_reason,
                ['failed_theoretical', 'voluntarily_deferred_theoretical'],
                true,
            );
            $eligibilityStatusMatches = $fixedRegistration->eligibility_reason === 'failed_theoretical'
                ? $eligibilityResultStatus?->status_code === 'failed'
                : in_array($eligibilityResultStatus?->status_code, ['incomplete', 'failed'], true);
            $policyMaximumMatches = $preconditionPolicy
                && (bool) $preconditionPolicy->is_active
                && abs((float) $theoreticalDefinitions->sum('max_mark') - (float) $preconditionPolicy->theoretical_max_mark) <= 0.001
                && ($practicalDefinitions->isEmpty()
                    || abs((float) $practicalDefinitions->sum('max_mark') - (float) $preconditionPolicy->practical_max_mark) <= 0.001);

            if (! $offering
                || ! $targetRegistration
                || $candidateTargetResults->count() !== 1
                || ! $targetResult
                || ! $targetOffering
                || ! $sourceAllowed
                || (int) $targetRegistration->student_id !== (int) $fixedRegistration->student_id
                || (int) $targetResult->student_course_registration_id !== (int) $fixedRegistration->student_course_registration_id
                || (int) $targetOffering->course_id !== (int) $offering->course_id
                || (int) $targetOffering->academic_program_id !== (int) $offering->academic_program_id
                || ! $eligibilityValid
                || ! $eligibilityStatusMatches
                || ! in_array($registrationStatus?->status_code, ['registered', 'completed'], true)
                || ! $targetResultStatus
                || ! (bool) $targetResultStatus->is_active
                || (bool) $targetResult->is_deprived
                || ($targetRegistration->result_status_id !== null
                    && (int) $targetRegistration->result_status_id !== (int) $targetResult->result_status_id)
                || ! $latestApproval
                || $approvalStatus?->status_code !== 'approved'
                || ! (bool) $approvalStatus?->is_active
                || $theoreticalDefinitions->count() !== 1
                || ! $policyMaximumMatches) {
                $issues->push($this->issue(
                    'fixed_roster_precondition_conflict',
                    'CONFLICT',
                    'القائمة الثابتة لم تعد تطابق المحاولة الرسمية أو الأهلية أو الاعتماد الأكاديمي.',
                    [
                        'supplementary_exam_offering_id' => (int) $fixedRegistration->supplementary_exam_offering_id,
                        'supplementary_exam_registration_id' => (int) $fixedRegistration->supplementary_exam_registration_id,
                        'student_course_registration_id' => (int) $fixedRegistration->student_course_registration_id,
                    ],
                ));
            }
        }
        if (! $scopeComplete) {
            $issues->push($this->issue(
                'scope_incomplete',
                'WARNING',
                'المطابقة تغطي فقط العروض ضمن نطاق الموظف؛ لا يمكن إعلان نجاح الدورة كلها.',
                ['visible_offerings' => $offerings->count(), 'period_offerings' => $allOfferings->count()],
            ));
        }
        if ($allOfferings->isEmpty()) {
            $issues->push($this->issue(
                'period_has_no_offerings',
                'WARNING',
                'لا توجد عروض تكميلية مرتبطة بهذه الدورة.',
                ['supplementary_exam_period_id' => (int) $period->getKey()],
            ));
        }
        foreach ($roster->groupBy('student_course_registration_id') as $targetRegistrationId => $targetRows) {
            if ($targetRows->count() > 1) {
                $issues->push($this->issue(
                    'duplicate_roster_target',
                    'CONFLICT',
                    'تظهر المحاولة الأكاديمية الرسمية نفسها أكثر من مرة في القائمة المثبتة.',
                    [
                        'student_course_registration_id' => (int) $targetRegistrationId,
                        'supplementary_exam_registration_ids' => $targetRows
                            ->pluck('supplementary_exam_registration_id')->map(fn ($id): int => (int) $id)->values()->all(),
                    ],
                ));
            }
        }
        foreach ($fixedRosterOccurrences->groupBy('student_course_registration_id') as $targetRegistrationId => $targetRows) {
            $periodIds = $targetRows->pluck('supplementary_exam_period_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();
            if ($periodIds->count() > 1) {
                $issues->push($this->issue(
                    'cross_period_duplicate_roster_target',
                    'CONFLICT',
                    'المحاولة الأكاديمية الرسمية مثبتة في أكثر من دورة تكميلية. يجب مراجعة التعارض قبل فتح التصحيح.',
                    [
                        'student_course_registration_id' => (int) $targetRegistrationId,
                        'supplementary_exam_period_ids' => $periodIds->all(),
                        'supplementary_exam_registration_ids' => $targetRows
                            ->pluck('supplementary_exam_registration_id')
                            ->map(fn ($id): int => (int) $id)
                            ->values()
                            ->all(),
                    ],
                ));
            }
        }

        $offeringReports = [];
        foreach ($offerings as $offering) {
            $offeringId = (int) $offering->getKey();
            $offeringIssues = collect();
            $offeringRegistrations = $registrationsByOffering->get($offeringId, collect())->values();
            $offeringRoster = $rosterByOffering->get($offeringId, collect())->values();
            $offeringResults = $resultsByOffering->get($offeringId, collect())->values();
            $offeringSubmissions = $submissionsByOffering->get($offeringId, collect());
            $latestVersion = $offeringSubmissions->max('submission_version');
            $latestSubmissions = $offeringSubmissions
                ->filter(fn (object $row): bool => (int) $row->submission_version === (int) $latestVersion)
                ->values();
            $submission = $latestSubmissions->first();
            $offeringMaterializedCount = 0;
            $offeringSources = $sourcesByOffering->get($offeringId, collect());
            $offeringMaterializations = $materializationsByOffering->get($offeringId, collect())->values();
            $offeringResultEvents = $offeringResults->flatMap(fn (object $result): Collection =>
                $eventsByResult->get((int) $result->supplementary_exam_grade_result_id, collect()));

            if (in_array($periodStatus, ['announced', 'registration_open', 'registration_closed'], true)
                && ($offeringResults->isNotEmpty()
                    || $offeringSubmissions->isNotEmpty()
                    || $offeringResultEvents->isNotEmpty()
                    || $offeringMaterializations->isNotEmpty())) {
                $offeringIssues->push($this->issue(
                    'premature_grading_artifacts',
                    'CONFLICT',
                    'توجد علامات أو دفعات أو أدلة ترحيل قبل فتح مرحلة التصحيح.',
                    ['supplementary_exam_offering_id' => $offeringId],
                ));
            }

            $invalidRegistrations = $offeringRegistrations->filter(fn (object $registration): bool =>
                ! (($registration->status === 'registered' && (int) $registration->current_slot === 1)
                    || ($registration->status === 'cancelled' && $registration->current_slot === null))
            )->values();
            if ($invalidRegistrations->isNotEmpty()) {
                $offeringIssues->push($this->issue(
                    'registration_state_mismatch',
                    'CONFLICT',
                    'تحتوي قائمة التسجيل على حالة غير متوافقة مع خانة التسجيل الحالي.',
                    [
                        'supplementary_exam_offering_id' => $offeringId,
                        'supplementary_exam_registration_ids' => $invalidRegistrations
                            ->pluck('supplementary_exam_registration_id')->map(fn ($id): int => (int) $id)->all(),
                    ],
                ));
            }

            $rosterIdsForOffering = $offeringRoster->pluck('supplementary_exam_registration_id')
                ->map(fn ($id): int => (int) $id);
            $orphanMaterializations = $offeringMaterializations->reject(fn (object $materialization): bool =>
                $rosterIdsForOffering->contains((int) $materialization->supplementary_exam_registration_id)
            )->values();
            if ($orphanMaterializations->isNotEmpty()) {
                $offeringIssues->push($this->issue(
                    'materialization_outside_current_roster',
                    'CONFLICT',
                    'يوجد ترحيل رسمي لا يرتبط بعضو حالي في القائمة المثبتة.',
                    [
                        'supplementary_exam_offering_id' => $offeringId,
                        'supplementary_exam_materialization_ids' => $orphanMaterializations
                            ->pluck('supplementary_exam_materialization_id')->map(fn ($id): int => (int) $id)->all(),
                    ],
                ));
            }

            if ($offeringSources->isEmpty()) {
                $offeringIssues->push($this->issue(
                    'offering_source_missing',
                    'CONFLICT',
                    'لا يوجد مصدر أكاديمي محدد للعرض التكميلي.',
                    ['supplementary_exam_offering_id' => $offeringId],
                ));
            }
            foreach ($offeringSources as $source) {
                $sourceOffering = $targetOfferingsById->get((int) $source->course_offering_id);
                if (! $sourceOffering
                    || (int) $sourceOffering->course_id !== (int) $offering->course_id
                    || (int) $sourceOffering->academic_program_id !== (int) $offering->academic_program_id) {
                    $offeringIssues->push($this->issue(
                        'offering_source_relationship_mismatch',
                        'CONFLICT',
                        'مصدر العرض التكميلي لا يطابق المقرر والبرنامج.',
                        [
                            'supplementary_exam_offering_id' => $offeringId,
                            'course_offering_id' => (int) $source->course_offering_id,
                        ],
                    ));
                }
            }

            if ($offeringRoster->isEmpty()) {
                $offeringIssues->push($this->issue(
                    'empty_roster',
                    'WARNING',
                    'لا توجد قائمة طلاب حالية للعرض التكميلي.',
                    ['supplementary_exam_offering_id' => $offeringId],
                ));
                if ($offeringResults->isNotEmpty()
                    || $offeringSubmissions->isNotEmpty()
                    || $offeringResultEvents->isNotEmpty()
                    || $offeringMaterializations->isNotEmpty()) {
                    $offeringIssues->push($this->issue(
                        'zero_roster_grading_artifacts',
                        'CONFLICT',
                        'يوجد إرسال أو نتيجة أو ترحيل لعرض لا يحتوي قائمة طلاب مثبتة.',
                        ['supplementary_exam_offering_id' => $offeringId],
                    ));
                }
            }
            if ($offeringRoster->isNotEmpty()) {
                if (! $submission) {
                    $offeringIssues->push($this->issue(
                        'source_submission_missing',
                        $publicationRequired ? 'CONFLICT' : 'WARNING',
                        'لا يوجد إرسال درجات للعرض.',
                        ['supplementary_exam_offering_id' => $offeringId],
                    ));
                } elseif ($latestSubmissions->count() !== 1) {
                    $offeringIssues->push($this->issue(
                        'source_submission_version_ambiguous',
                        'CONFLICT',
                        'إصدار الإرسال الأحدث غير فريد.',
                        ['supplementary_exam_offering_id' => $offeringId, 'submission_version' => (int) $latestVersion],
                    ));
                } elseif ($submission->status !== 'published' || $submission->published_at === null) {
                    $offeringIssues->push($this->issue(
                        'source_submission_not_published',
                        $publicationRequired ? 'CONFLICT' : 'WARNING',
                        'إرسال الدرجات الأحدث غير منشور.',
                        ['supplementary_exam_offering_id' => $offeringId],
                    ));
                } elseif ($this->later($submission->updated_at, $submission->published_at)) {
                    $offeringIssues->push($this->issue(
                        'source_submission_drift',
                        'CONFLICT',
                        'تغيّر إرسال الدرجات بعد نشره.',
                        ['supplementary_exam_offering_id' => $offeringId],
                    ));
                }
            }

            $rosterIds = $offeringRoster->pluck('supplementary_exam_registration_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
            $resultRosterIds = $offeringResults->pluck('supplementary_exam_registration_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
            if ($rosterIds !== $resultRosterIds) {
                $hasUnexpectedOrDuplicateResults = array_diff($resultRosterIds, $rosterIds) !== []
                    || count($resultRosterIds) !== count(array_unique($resultRosterIds));
                $offeringIssues->push($this->issue(
                    'roster_result_mismatch',
                    $hasUnexpectedOrDuplicateResults || $publicationRequired ? 'CONFLICT' : 'WARNING',
                    'نتائج العرض لا تطابق قائمة الطلاب الحالية.',
                    ['supplementary_exam_offering_id' => $offeringId],
                ));
            }

            foreach ($offeringRoster as $registration) {
                $registrationId = (int) $registration->supplementary_exam_registration_id;
                $targetRegistrationId = (int) $registration->student_course_registration_id;
                $candidateIds = [
                    'supplementary_exam_offering_id' => $offeringId,
                    'supplementary_exam_registration_id' => $registrationId,
                    'student_course_registration_id' => $targetRegistrationId,
                    'student_id' => (int) $registration->student_id,
                ];
                $candidateResults = $resultsByRegistration->get($registrationId, collect());
                $result = $candidateResults->first();

                if ($candidateResults->isEmpty() || ! $result) {
                    $offeringIssues->push($this->issue(
                        'source_result_missing_or_ambiguous',
                        $publicationRequired ? 'CONFLICT' : 'WARNING',
                        'نتيجة الطالب التكميلية لم تُنشأ بعد.',
                        $candidateIds,
                    ));
                    continue;
                }
                if ($candidateResults->count() !== 1) {
                    $offeringIssues->push($this->issue(
                        'source_result_missing_or_ambiguous',
                        'CONFLICT',
                        'نتيجة الطالب التكميلية مكررة أو ملتبسة.',
                        $candidateIds,
                    ));
                    continue;
                }

                $candidateIds['supplementary_exam_grade_result_id'] = (int) $result->supplementary_exam_grade_result_id;
                $sourceIdentityMatches = (int) $result->supplementary_exam_offering_id === $offeringId
                    && (int) $result->student_course_registration_id === $targetRegistrationId
                    && (int) $result->student_id === (int) $registration->student_id;
                if (! $sourceIdentityMatches) {
                    $offeringIssues->push($this->issue(
                        'source_result_mismatch',
                        'CONFLICT',
                        'هوية النتيجة التكميلية لا تطابق الطالب والمحاولة.',
                        $candidateIds,
                    ));
                }
                if ($result->theoretical_mark === null) {
                    $offeringIssues->push($this->issue(
                        'source_theoretical_mark_missing',
                        $publicationRequired ? 'CONFLICT' : 'WARNING',
                        'الدرجة النظرية التكميلية مفقودة.',
                        $candidateIds,
                    ));
                }
                if ($result->status !== 'published' || $result->published_at === null) {
                    $offeringIssues->push($this->issue(
                        'source_result_not_published',
                        $publicationRequired ? 'CONFLICT' : 'WARNING',
                        'نتيجة الطالب غير منشورة.',
                        $candidateIds,
                    ));
                } elseif ($this->later($result->updated_at, $result->published_at)) {
                    $offeringIssues->push($this->issue(
                        'source_result_drift',
                        'CONFLICT',
                        'تغيّرت النتيجة التكميلية بعد نشرها.',
                        $candidateIds,
                    ));
                }
                if ($submission && (int) $result->submission_version !== (int) $submission->submission_version) {
                    $offeringIssues->push($this->issue(
                        'source_submission_version_mismatch',
                        'CONFLICT',
                        'النتيجة مرتبطة بإصدار إرسال قديم.',
                        $candidateIds,
                    ));
                }

                $sourceEvent = null;
                if ($result->status === 'published' && $result->published_at !== null) {
                    $resultEvents = $eventsByResult->get((int) $result->supplementary_exam_grade_result_id, collect());
                    $publishedEvents = $resultEvents->filter(fn (object $event): bool =>
                        $event->event_type === 'published'
                        && $event->from_status === 'approved'
                        && $event->to_status === 'published'
                        && $submission
                        && (int) $event->supplementary_exam_grade_submission_id === (int) $submission->supplementary_exam_grade_submission_id
                        && (int) $event->submission_version === (int) $submission->submission_version
                    )->values();
                    $sourceEvent = $publishedEvents->first();
                    if ($publishedEvents->count() !== 1
                        || ! $sourceEvent
                        || (int) $resultEvents->last()?->supplementary_exam_grade_event_id !== (int) $sourceEvent->supplementary_exam_grade_event_id
                        || $this->decimal($sourceEvent->theoretical_mark) !== $this->decimal($result->theoretical_mark)
                        || $this->earlier($sourceEvent->created_at, $result->published_at)
                        || ($submission && $this->earlier($sourceEvent->created_at, $submission->published_at))) {
                        $offeringIssues->push($this->issue(
                            'source_publication_event_mismatch',
                            'CONFLICT',
                            'دليل نشر النتيجة مفقود أو غير متطابق.',
                            $candidateIds,
                        ));
                    }
                }

                $sourceMaterializations = $materializationsBySource->get($registrationId, collect());
                $targetMaterializations = $materializationsByTarget->get($targetRegistrationId, collect());
                if ($sourceMaterializations->count() > 1 || $targetMaterializations->count() > 1) {
                    $offeringIssues->push($this->issue(
                        'duplicate_materialization_provenance',
                        'CONFLICT',
                        'يوجد أكثر من سجل إثبات للمصدر أو المحاولة الرسمية.',
                        $candidateIds,
                    ));
                }
                $materialization = $sourceMaterializations->first(fn (object $row): bool =>
                    (int) $row->supplementary_exam_offering_id === $offeringId
                );
                $foreignTarget = $targetMaterializations->first(fn (object $row): bool =>
                    (int) $row->supplementary_exam_registration_id !== $registrationId
                    || (int) $row->supplementary_exam_offering_id !== $offeringId
                );
                if ($foreignTarget) {
                    $offeringIssues->push($this->issue(
                        'regular_attempt_already_materialized',
                        'CONFLICT',
                        'سبق ترحيل المحاولة الرسمية من مصدر تكميلي آخر.',
                        $candidateIds + [
                            'conflicting_supplementary_exam_materialization_id' => (int) $foreignTarget->supplementary_exam_materialization_id,
                            'conflicting_supplementary_exam_offering_id' => (int) $foreignTarget->supplementary_exam_offering_id,
                        ],
                    ));
                }

                $targetRegistration = $targetRegistrationsById->get($targetRegistrationId);
                $candidateTargetResults = $targetResultsByRegistration->get($targetRegistrationId, collect());
                $targetResult = $candidateTargetResults->first();
                $targetOffering = $targetOfferingsById->get((int) ($targetRegistration?->course_offering_id ?? 0));
                $allowedSourceIds = $sourcesByOffering->get($offeringId, collect())
                    ->pluck('course_offering_id')->map(fn ($id): int => (int) $id);
                $targetExists = $targetRegistration
                    && $candidateTargetResults->count() === 1
                    && $targetResult
                    && $targetOffering;
                if (! $targetExists) {
                    $offeringIssues->push($this->issue(
                        'official_target_missing_or_ambiguous',
                        'CONFLICT',
                        'المحاولة أو النتيجة الرسمية مفقودة أو مكررة.',
                        $candidateIds,
                    ));
                } else {
                    $targetRelationshipMatches = (int) $targetRegistration->student_id === (int) $registration->student_id
                        && (int) $targetResult->student_course_registration_id === $targetRegistrationId
                        && (int) $targetOffering->course_id === (int) $offering->course_id
                        && (int) $targetOffering->academic_program_id === (int) $offering->academic_program_id
                        && $allowedSourceIds->contains((int) $targetOffering->course_offering_id);
                    if (! $targetRelationshipMatches) {
                        $offeringIssues->push($this->issue(
                            'offering_source_target_mismatch',
                            'CONFLICT',
                            'علاقة العرض والمصدر والمقرر والبرنامج غير متطابقة.',
                            $candidateIds,
                        ));
                    }
                }

                $eligibilityValid = in_array(
                    $registration->eligibility_reason,
                    ['failed_theoretical', 'voluntarily_deferred_theoretical'],
                    true,
                );
                if (! $eligibilityValid) {
                    $offeringIssues->push($this->issue(
                        'fixed_roster_eligibility_invalid',
                        'CONFLICT',
                        'سبب الأهلية المحفوظ في القائمة الثابتة غير صالح.',
                        $candidateIds,
                    ));
                }

                if ($targetExists) {
                    $registrationStatus = $registrationStatuses->get((int) $targetRegistration->registration_status_id);
                    $targetResultStatus = $resultStatuses->get((int) $targetResult->result_status_id);
                    $eligibilityResultStatus = $materialization
                        ? $resultStatuses->get((int) $materialization->before_result_status_id)
                        : $targetResultStatus;
                    $latestTargetApproval = $approvalsByOffering
                        ->get((int) $targetOffering->course_offering_id, collect())
                        ->sortByDesc('grade_approval_id')->first();
                    $latestApprovalStatus = $latestTargetApproval
                        ? $approvalStatuses->get((int) $latestTargetApproval->approval_status_id)
                        : null;
                    $targetRequiredComponents = $componentsByOffering
                        ->get((int) $targetOffering->course_offering_id, collect())
                        ->filter(fn (object $row): bool => (bool) $row->is_required)
                        ->whereIn('component_type', ['theoretical', 'practical']);
                    $theoreticalDefinitions = $targetRequiredComponents->where('component_type', 'theoretical');
                    $practicalDefinitions = $targetRequiredComponents->where('component_type', 'practical');
                    $theoreticalMaximum = (float) $theoreticalDefinitions->sum('max_mark');
                    $practicalMaximum = (float) $practicalDefinitions->sum('max_mark');
                    $preconditionPolicy = $materialization
                        ? $gradingPolicies->get((int) $materialization->grading_policy_id)
                        : $canonicalPolicy;
                    $policyMaximumMatches = $preconditionPolicy
                        && (bool) $preconditionPolicy->is_active
                        && abs($theoreticalMaximum - (float) $preconditionPolicy->theoretical_max_mark) <= 0.001
                        && ($practicalDefinitions->isEmpty()
                            || abs($practicalMaximum - (float) $preconditionPolicy->practical_max_mark) <= 0.001);
                    $eligibilityStatusMatches = $registration->eligibility_reason === 'failed_theoretical'
                        ? $eligibilityResultStatus?->status_code === 'failed'
                        : in_array($eligibilityResultStatus?->status_code, ['incomplete', 'failed'], true);
                    if (! in_array($registrationStatus?->status_code, ['registered', 'completed'], true)
                        || ! $targetResultStatus
                        || ! (bool) $targetResultStatus->is_active
                        || (bool) $targetResult->is_deprived
                        || ($targetRegistration->result_status_id !== null
                            && (int) $targetRegistration->result_status_id !== (int) $targetResult->result_status_id)
                        || ! $latestTargetApproval
                        || $latestApprovalStatus?->status_code !== 'approved'
                        || ! (bool) $latestApprovalStatus?->is_active
                        || $theoreticalDefinitions->count() !== 1
                        || ! $policyMaximumMatches
                        || (float) $result->theoretical_mark < 0
                        || (float) $result->theoretical_mark > $theoreticalMaximum
                        || ($eligibilityValid && ! $eligibilityStatusMatches)) {
                        $offeringIssues->push($this->issue(
                            'materialization_precondition_conflict',
                            'CONFLICT',
                            'المحاولة الرسمية أو اعتمادها أو مكونات العلامة لم تعد تحقق شروط الترحيل.',
                            $candidateIds,
                        ));
                    }
                }

                if (! $materialization) {
                    if ($result->status === 'published') {
                        $offeringIssues->push($this->issue(
                            'published_result_not_materialized',
                            $terminal ? 'CONFLICT' : 'WARNING',
                            'النتيجة منشورة لكنها لم ترحّل إلى السجل الرسمي.',
                            $candidateIds,
                        ));
                    }
                    continue;
                }

                $offeringMaterializedCount++;
                $candidateIds['supplementary_exam_materialization_id'] = (int) $materialization->supplementary_exam_materialization_id;
                $sourceSnapshotMatches = $submission && $sourceEvent
                    && (int) $materialization->supplementary_exam_grade_result_id === (int) $result->supplementary_exam_grade_result_id
                    && (int) $materialization->supplementary_exam_grade_event_id === (int) $sourceEvent->supplementary_exam_grade_event_id
                    && (int) $materialization->supplementary_exam_grade_submission_id === (int) $submission->supplementary_exam_grade_submission_id
                    && (int) $materialization->student_course_registration_id === $targetRegistrationId
                    && (int) $materialization->source_submission_version === (int) $submission->submission_version
                    && (int) $materialization->student_id === (int) $registration->student_id
                    && $this->decimal($materialization->source_theoretical_mark) === $this->decimal($result->theoretical_mark)
                    && $this->timestamp($materialization->source_registration_updated_at) === $this->timestamp($registration->updated_at)
                    && $this->timestamp($materialization->source_result_published_at) === $this->timestamp($result->published_at)
                    && $this->timestamp($materialization->source_submission_published_at) === $this->timestamp($submission->published_at)
                    && $this->timestamp($materialization->source_result_updated_at) === $this->timestamp($result->updated_at)
                    && $this->timestamp($materialization->source_submission_updated_at) === $this->timestamp($submission->updated_at)
                    && $materialization->materialized_at !== null
                    && $materialization->created_at !== null
                    && $this->timestamp($materialization->materialized_at) === $this->timestamp($materialization->created_at)
                    && ! $this->earlier($materialization->materialized_at, $result->published_at)
                    && ! $this->earlier($materialization->materialized_at, $submission->published_at);
                if (! $sourceSnapshotMatches) {
                    $offeringIssues->push($this->issue(
                        'materialization_source_mismatch',
                        'CONFLICT',
                        'إثبات الترحيل لا يطابق مصدر النشر.',
                        $candidateIds,
                    ));
                }

                if ((int) $materialization->student_course_registration_id !== $targetRegistrationId
                    || ($targetResult && (int) $materialization->student_course_result_id !== (int) $targetResult->student_course_result_id)) {
                    $offeringIssues->push($this->issue(
                        'materialization_target_mismatch',
                        'CONFLICT',
                        'إثبات الترحيل يشير إلى محاولة أو نتيجة رسمية مختلفة عن القائمة الثابتة.',
                        $candidateIds,
                    ));
                }

                $postingEvents = $materializationEventsByMaterialization
                    ->get((int) $materialization->supplementary_exam_materialization_id, collect());
                $postingEvent = $postingEvents->first();
                if ($postingEvents->count() !== 1
                    || ! $postingEvent
                    || $postingEvent->event_type !== 'official_result_materialized'
                    || (int) $postingEvent->supplementary_exam_offering_id !== $offeringId
                    || (int) $postingEvent->supplementary_exam_registration_id !== $registrationId
                    || (int) $postingEvent->source_submission_version !== (int) $materialization->source_submission_version
                    || (int) $postingEvent->actor_user_id !== (int) $materialization->materialized_by_user_id
                    || $postingEvent->created_at === null
                    || $this->timestamp($postingEvent->created_at) !== $this->timestamp($materialization->created_at)) {
                    $offeringIssues->push($this->issue(
                        'materialization_event_mismatch',
                        'CONFLICT',
                        'حدث الترحيل الرسمي مفقود أو غير متطابق.',
                        $candidateIds,
                    ));
                }

                if (! $targetExists) {
                    continue;
                }
                if ((int) $targetRegistration->registration_status_id !== (int) $materialization->preserved_registration_status_id) {
                    $offeringIssues->push($this->issue(
                        'registration_status_drift',
                        'CONFLICT',
                        'تغيّرت حالة التسجيل المحفوظة بعد الترحيل.',
                        $candidateIds,
                    ));
                }

                $latestApproval = $approvalsByOffering
                    ->get((int) $targetOffering->course_offering_id, collect())
                    ->sortByDesc('grade_approval_id')->first();
                $approvalStatus = $latestApproval
                    ? $approvalStatuses->get((int) $latestApproval->approval_status_id)
                    : null;
                if (! $latestApproval
                    || (int) $latestApproval->grade_approval_id !== (int) $materialization->grade_approval_id
                    || $approvalStatus?->status_code !== 'approved'
                    || ! (bool) $approvalStatus?->is_active
                    || $this->timestamp($latestApproval->updated_at) !== $this->timestamp($materialization->grade_approval_updated_at)) {
                    $offeringIssues->push($this->issue(
                        'grade_approval_drift',
                        'CONFLICT',
                        'اعتماد المحاولة الرسمية لا يطابق الإثبات المحفوظ.',
                        $candidateIds,
                    ));
                }

                $currentComponents = $componentsByOffering
                    ->get((int) $targetOffering->course_offering_id, collect())
                    ->filter(fn (object $row): bool => (bool) $row->is_required)
                    ->whereIn('component_type', ['theoretical', 'practical']);
                $candidateGradeRows = $gradeRowsByRegistration->get($targetRegistrationId, collect());
                $theoreticalSnapshot = $this->componentSnapshot($currentComponents, $candidateGradeRows, 'theoretical');
                $practicalSnapshot = $this->componentSnapshot($currentComponents, $candidateGradeRows, 'practical');
                $storedTheoryBefore = $this->snapshot($materialization->before_theoretical_components_snapshot);
                $storedTheory = $this->snapshot($materialization->after_theoretical_components_snapshot);
                $storedPractical = $this->snapshot($materialization->practical_components_snapshot);

                $theoreticalTransitionMatches = count($storedTheoryBefore) === 1
                    && count($storedTheory) === 1
                    && $storedTheoryBefore[0]['student_grade_component_id'] === $storedTheory[0]['student_grade_component_id']
                    && $storedTheoryBefore[0]['grade_component_id'] === $storedTheory[0]['grade_component_id']
                    && $storedTheoryBefore[0]['grade_status'] === $storedTheory[0]['grade_status']
                    && $this->decimal($storedTheory[0]['mark']) === $this->decimal($materialization->source_theoretical_mark)
                    && $this->decimal(collect($storedTheoryBefore)->sum(fn (array $row): float => (float) ($row['mark'] ?? 0)))
                        === $this->decimal($materialization->before_theoretical_total);
                if (! $theoreticalTransitionMatches) {
                    $offeringIssues->push($this->issue(
                        'theoretical_component_provenance_mismatch',
                        'CONFLICT',
                        'لقطة المكوّن النظري قبل الترحيل لا تطابق انتقال المكوّن المحفوظ.',
                        $candidateIds,
                    ));
                }

                if ($currentComponents->where('component_type', 'theoretical')->count() !== 1
                    || count($theoreticalSnapshot) !== 1
                    || $theoreticalSnapshot[0]['mark'] === null
                    || $theoreticalSnapshot[0]['grade_status'] !== 'approved') {
                    $offeringIssues->push($this->issue(
                        'theoretical_components_incomplete',
                        'CONFLICT',
                        'دليل المكوّن النظري الرسمي مفقود أو ملتبس.',
                        $candidateIds,
                    ));
                } elseif ($storedTheory !== $theoreticalSnapshot
                    || $this->decimal($theoreticalSnapshot[0]['mark']) !== $this->decimal($targetResult->theoretical_total)
                    || $this->decimal($theoreticalSnapshot[0]['mark']) !== $this->decimal($materialization->source_theoretical_mark)) {
                    $offeringIssues->push($this->issue(
                        'theoretical_component_drift',
                        'CONFLICT',
                        'المكوّن النظري أو مجموعه لا يطابق الترحيل.',
                        $candidateIds,
                    ));
                }

                $practicalDefinitions = $currentComponents->where('component_type', 'practical');
                $practicalSum = collect($practicalSnapshot)->sum(fn (array $row): float => (float) ($row['mark'] ?? 0));
                if ($practicalDefinitions->count() !== count($practicalSnapshot)
                    || collect($practicalSnapshot)->contains(fn (array $row): bool => $row['mark'] === null || $row['grade_status'] !== 'approved')) {
                    $offeringIssues->push($this->issue(
                        'practical_components_incomplete',
                        'CONFLICT',
                        'دليل المكوّنات العملية غير مكتمل.',
                        $candidateIds,
                    ));
                } elseif ($practicalDefinitions->isEmpty()
                    ? ($storedPractical !== []
                        || ! in_array($this->decimal($targetResult->practical_total), [null, '0.00'], true))
                    : ($storedPractical !== $practicalSnapshot
                        || $this->decimal($practicalSum) !== $this->decimal($targetResult->practical_total))) {
                    $offeringIssues->push($this->issue(
                        'practical_component_drift',
                        'CONFLICT',
                        'المكوّنات العملية أو مجموعها تغيّرت بعد الترحيل.',
                        $candidateIds,
                    ));
                }

                $recordedPolicy = $gradingPolicies->get((int) $materialization->grading_policy_id);
                $officialResultStatus = $resultStatuses->get((int) $targetResult->result_status_id);
                $requiresPractical = $practicalDefinitions->isNotEmpty();
                $theoreticalValue = count($theoreticalSnapshot) === 1
                    ? (float) ($theoreticalSnapshot[0]['mark'] ?? 0)
                    : null;
                $canonicalFinal = $theoreticalValue === null
                    ? null
                    : round($theoreticalValue + ($requiresPractical ? $practicalSum : 0), 2);
                $marksInRange = $recordedPolicy && $theoreticalValue !== null
                    && $theoreticalValue >= 0
                    && $theoreticalValue <= (float) $recordedPolicy->theoretical_max_mark
                    && (! $requiresPractical
                        || ($practicalSum >= 0 && $practicalSum <= (float) $recordedPolicy->practical_max_mark));
                $canonicalFailed = $marksInRange && (
                    $theoreticalValue < (float) $recordedPolicy->minimum_theoretical_mark
                    || ($requiresPractical && (
                        $practicalSum < (float) $recordedPolicy->minimum_practical_mark
                    ))
                    || $canonicalFinal < (float) $recordedPolicy->minimum_final_mark
                );
                $canonicalStatus = $canonicalFailed ? 'failed' : 'passed';
                if (! $recordedPolicy
                    || ! (bool) $recordedPolicy->is_active
                    || ! $marksInRange
                    || ! $officialResultStatus
                    || ! (bool) $officialResultStatus->is_active
                    || $officialResultStatus->status_code !== $canonicalStatus
                    || (int) $targetRegistration->result_status_id !== (int) $targetResult->result_status_id
                    || $canonicalFinal === null
                    || $this->decimal($canonicalFinal) !== $this->decimal($targetResult->final_mark)
                    || $this->decimal($canonicalFinal) !== $this->decimal($materialization->after_final_mark)
                    || (bool) $targetResult->is_deprived) {
                    $offeringIssues->push($this->issue(
                        'grading_policy_or_outcome_mismatch',
                        'CONFLICT',
                        'سياسة التقييم أو العلامة النهائية أو حالة النتيجة لا تطابق الحساب الرسمي.',
                        $candidateIds,
                    ));
                }

                $officialFields = [
                    'theoretical_total', 'practical_total', 'coursework_total', 'final_mark',
                    'result_status_id', 'is_deprived', 'calculated_at', 'calculated_by_user_id',
                    'updated_at',
                ];
                if ($resultAnnouncedAtAvailable) {
                    $officialFields[] = 'result_announced_at';
                }
                foreach ($officialFields as $field) {
                    $storedField = $field === 'updated_at' ? 'after_result_updated_at' : "after_{$field}";
                    if (! $this->sameField($field, $materialization->{$storedField}, $targetResult->{$field})) {
                        $code = match ($field) {
                            'coursework_total' => 'coursework_total_drift',
                            'is_deprived' => 'attendance_deprivation_drift',
                            default => 'official_target_drift',
                        };
                        $offeringIssues->push($this->issue(
                            $code,
                            'CONFLICT',
                            'النتيجة الرسمية تغيّرت عن لقطة الترحيل.',
                            $candidateIds + ['field' => $field],
                        ));
                    }
                }
                if (! $this->sameField('result_status_id', $materialization->after_registration_result_status_id, $targetRegistration->result_status_id)
                    || ! $this->sameField('updated_at', $materialization->after_registration_updated_at, $targetRegistration->updated_at)) {
                    $offeringIssues->push($this->issue(
                        'official_registration_drift',
                        'CONFLICT',
                        'سجل التسجيل الرسمي تغيّر عن لقطة الترحيل.',
                        $candidateIds,
                    ));
                }
                foreach (['practical_total', 'coursework_total', 'is_deprived', 'result_announced_at'] as $preserved) {
                    if (! $this->sameField($preserved, $materialization->{"before_{$preserved}"}, $materialization->{"after_{$preserved}"})) {
                        $offeringIssues->push($this->issue(
                            'protected_official_field_changed',
                            'CONFLICT',
                            'تغيّر حقل رسمي كان يجب الحفاظ عليه.',
                            $candidateIds + ['field' => $preserved],
                        ));
                    }
                }
            }

            $state = $this->state($offeringIssues);
            $counts = [
                'roster' => $offeringRoster->count(),
                'graded' => $offeringResults->filter(fn (object $row): bool => $row->theoretical_mark !== null)->count(),
                'published' => $offeringResults->where('status', 'published')->count(),
                'materialized' => $offeringMaterializedCount,
            ];
            $offeringReports[] = [
                'supplementary_exam_offering_id' => $offeringId,
                'academic_program_id' => (int) $offering->academic_program_id,
                'course_id' => (int) $offering->course_id,
                'academic_program' => [
                    'academic_program_id' => (int) $offering->academic_program_id,
                    'program_code' => $offering->academicProgram?->program_code,
                    'program_name' => $offering->academicProgram?->program_name,
                ],
                'course' => [
                    'course_id' => (int) $offering->course_id,
                    'course_code' => $offering->course?->course_code,
                    'course_name' => $offering->course?->course_name,
                ],
                'counts' => $counts,
                'state' => $state,
                'operational_status' => $this->operationalStatus(
                    $state,
                    $counts,
                    $periodStatus,
                    $submission?->status,
                ),
                'issues' => $offeringIssues->values()->all(),
            ];
            $issues = $issues->concat($offeringIssues);
        }

        $counts = [
            'roster' => array_sum(array_column(array_column($offeringReports, 'counts'), 'roster')),
            'graded' => array_sum(array_column(array_column($offeringReports, 'counts'), 'graded')),
            'published' => array_sum(array_column(array_column($offeringReports, 'counts'), 'published')),
            'materialized' => array_sum(array_column(array_column($offeringReports, 'counts'), 'materialized')),
        ];
        if ($terminal) {
            $event = $terminalEvents->first();
            if ($counts['roster'] === 0) {
                $issues->push($this->issue(
                    'terminal_period_empty_roster',
                    'CONFLICT',
                    'الدورة النهائية لا تحتوي أي طالب في قائمة تكميلية ثابتة.',
                    ['supplementary_exam_period_id' => (int) $period->getKey()],
                ));
            }
            if ($counts['materialized'] !== $counts['roster'] || $counts['published'] !== $counts['roster']) {
                $issues->push($this->issue(
                    'terminal_coverage_incomplete',
                    'CONFLICT',
                    'الدورة نهائية ولكن النشر أو الترحيل لا يغطي كامل القائمة.',
                    ['supplementary_exam_period_id' => (int) $period->getKey()],
                ));
            }
            if ($terminalEvents->count() !== 1
                || ! $event
                || $event->from_status !== MaterializationGovernance::SOURCE_PERIOD_STATUS
                || $event->to_status !== MaterializationGovernance::TERMINAL_PERIOD_STATUS
                || $event->actor_user_id === null
                || $event->created_at === null
                || $materializations->contains(fn (object $row): bool =>
                    $row->materialized_at === null || $this->earlier($event->created_at, $row->materialized_at))) {
                $issues->push($this->issue(
                    'terminal_event_mismatch',
                    'CONFLICT',
                    'حدث إغلاق الدورة النهائي مفقود أو مكرر أو غير متطابق.',
                    ['supplementary_exam_period_id' => (int) $period->getKey()],
                ));
            }
        } elseif ($terminalEvents->isNotEmpty()) {
            $issues->push($this->issue(
                'terminal_event_before_transition',
                'CONFLICT',
                'يوجد حدث ترحيل نهائي قبل انتقال حالة الدورة.',
                ['supplementary_exam_period_id' => (int) $period->getKey()],
            ));
        } elseif ($counts['roster'] > 0 && $counts['materialized'] === $counts['roster']) {
            $issues->push($this->issue(
                'terminal_transition_pending',
                'WARNING',
                'الترحيل مكتمل لكن حالة الدورة لم تنتقل إلى الحالة النهائية.',
                ['supplementary_exam_period_id' => (int) $period->getKey()],
            ));
        }

        return [
            'period' => [
                'supplementary_exam_period_id' => (int) $period->getKey(),
                'academic_year_id' => (int) $period->academic_year_id,
                'semester_id' => (int) $period->semester_id,
                'status' => $periodStatus,
            ],
            'state' => $this->state($issues),
            'scope_complete' => $scopeComplete,
            'action_flags' => [
                'can_open_grading' => $periodStatus === 'registration_closed'
                    && $scopeComplete
                    && $actualScopes->contains(fn (array $scope): bool => $scope['type'] === 'university')
                    && $counts['roster'] > 0
                    && ! $issues->contains(fn (array $issue): bool => $issue['severity'] === 'CONFLICT'),
            ],
            'counts' => $counts,
            'issues' => $issues->values()->all(),
            'offerings' => $offeringReports,
        ];
    }

    private function tableRows(string $table, string $column, Collection|array $ids): Collection
    {
        $ids = collect($ids)->filter(fn ($id): bool => $id !== null)->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return DB::table($table)->whereIn($column, $ids)->orderBy($this->primaryKey($table))->get();
    }

    private function primaryKey(string $table): string
    {
        return match ($table) {
            'student_course_registrations' => 'student_course_registration_id',
            'student_course_results' => 'student_course_result_id',
            'course_offerings' => 'course_offering_id',
            'grade_approvals' => 'grade_approval_id',
            'grade_components' => 'grade_component_id',
            'student_grade_components' => 'student_grade_component_id',
            'approval_statuses' => 'approval_status_id',
            'registration_statuses' => 'registration_status_id',
            'result_statuses' => 'result_status_id',
            'grading_policies' => 'grading_policy_id',
            default => rtrim($table, 's').'_id',
        };
    }

    private function componentSnapshot(Collection $components, Collection $gradeRows, string $type): array
    {
        $componentIds = $components->where('component_type', $type)
            ->pluck('grade_component_id')->map(fn ($id): int => (int) $id);

        return $gradeRows->whereIn('grade_component_id', $componentIds)
            ->sortBy('student_grade_component_id')
            ->map(fn (object $row): array => [
                'student_grade_component_id' => (int) $row->student_grade_component_id,
                'grade_component_id' => (int) $row->grade_component_id,
                'mark' => $this->decimal($row->mark),
                'grade_status' => (string) $row->grade_status,
                'updated_at' => $this->timestamp($row->updated_at),
            ])->values()->all();
    }

    private function snapshot(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return collect(is_array($value) ? $value : [])->map(fn (array $row): array => [
            'student_grade_component_id' => (int) ($row['student_grade_component_id'] ?? 0),
            'grade_component_id' => (int) ($row['grade_component_id'] ?? 0),
            'mark' => $this->decimal($row['mark'] ?? null),
            'grade_status' => (string) ($row['grade_status'] ?? ''),
            'updated_at' => $this->timestamp($row['updated_at'] ?? null),
        ])->values()->all();
    }

    private function issue(string $code, string $severity, string $message, array $identifiers): array
    {
        return compact('code', 'severity', 'message', 'identifiers');
    }

    private function state(Collection $issues): string
    {
        if ($issues->contains(fn (array $issue): bool => $issue['severity'] === 'CONFLICT')) {
            return 'CONFLICT';
        }

        return $issues->isNotEmpty() ? 'WARNING' : 'PASS';
    }

    private function operationalStatus(
        string $state,
        array $counts,
        string $periodStatus,
        ?string $submissionStatus,
    ): string
    {
        if ($state === 'CONFLICT') {
            return 'conflict_requires_review';
        }
        if ($periodStatus === MaterializationGovernance::TERMINAL_PERIOD_STATUS
            && $counts['roster'] === $counts['materialized']) {
            return 'officially_materialized';
        }

        $submissionOperationalStatus = match ($submissionStatus) {
            'submitted' => 'grades_submitted',
            'returned' => 'returned_for_correction',
            'approved' => 'grades_approved',
            'published' => 'results_published',
            'draft' => 'awaiting_grade_entry',
            default => null,
        };
        if ($submissionOperationalStatus !== null) {
            return $submissionOperationalStatus;
        }

        if ($periodStatus === 'registration_open') {
            return 'registration_open';
        }
        if ($periodStatus === 'registration_closed') {
            return 'registration_closed';
        }
        if ($counts['roster'] === 0) {
            return 'no_candidates';
        }

        return match ($periodStatus) {
            'grading_open' => 'awaiting_grade_entry',
            'grading_submitted' => 'grades_submitted',
            'results_approved' => 'grades_approved',
            'results_published' => 'results_published',
            default => $state === 'WARNING' ? 'workflow_incomplete' : 'reconciled',
        };
    }

    /** @return Collection<int, int> */
    private function mutableProgramIds(Collection $offerings, Collection $scopes): Collection
    {
        $programIds = $offerings->pluck('academic_program_id')->map(fn ($id): int => (int) $id)->unique();
        if ($scopes->contains(fn (array $scope): bool => $scope['type'] === 'university')) {
            return $programIds->values();
        }

        $directPrograms = $scopes->where('type', 'program')->pluck('id')->map(fn ($id): int => (int) $id);
        $departments = $scopes->where('type', 'department')->pluck('id')->map(fn ($id): int => (int) $id);
        $colleges = $scopes->where('type', 'college')->pluck('id')->map(fn ($id): int => (int) $id);

        return $offerings->filter(function (SupplementaryExamOffering $offering) use (
            $colleges,
            $departments,
            $directPrograms,
        ): bool {
            $program = $offering->academicProgram;

            return $directPrograms->contains((int) $offering->academic_program_id)
                || $departments->contains((int) $program?->department_id)
                || $colleges->contains((int) $program?->department?->college_id);
        })->pluck('academic_program_id')->map(fn ($id): int => (int) $id)->unique()->values();
    }

    private function sameField(string $field, mixed $left, mixed $right): bool
    {
        if (in_array($field, ['theoretical_total', 'practical_total', 'coursework_total', 'final_mark'], true)) {
            return $this->decimal($left) === $this->decimal($right);
        }
        if (str_ends_with($field, '_at') || $field === 'updated_at') {
            return $this->timestamp($left) === $this->timestamp($right);
        }
        if ($left === null || $right === null) {
            return $left === $right;
        }

        return (string) (int) $left === (string) (int) $right;
    }

    private function decimal(mixed $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 2, '.', '');
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof CarbonInterface
            ? $value->format('Y-m-d H:i:s')
            : date('Y-m-d H:i:s', strtotime((string) $value));
    }

    private function later(mixed $left, mixed $right): bool
    {
        return $left !== null && $right !== null && strtotime((string) $left) > strtotime((string) $right);
    }

    private function earlier(mixed $left, mixed $right): bool
    {
        return $left === null || $right === null || strtotime((string) $left) < strtotime((string) $right);
    }

    private function assertAuthorized(User $actor): void
    {
        if (! $actor->isExamOfficer()
            || ! $actor->effectivePermissions()->contains(GradingGovernance::REVIEW)) {
            $this->fail(
                'An actual Exam Officer role and assigned supplementary review permission are required.',
                'supplementary_reconciliation_forbidden',
                403,
            );
        }
    }

    private function fail(string $message, string $code, int $status): never
    {
        throw new GradeException($message, status: $status, errorCode: $code);
    }
}
