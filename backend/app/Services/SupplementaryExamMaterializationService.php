<?php

namespace App\Services;

use App\Exceptions\GradeException;
use App\Models\CourseOffering;
use App\Models\GradeApproval;
use App\Models\GradeComponent;
use App\Models\RegistrationStatus;
use App\Models\ResultStatus;
use App\Models\StudentCourseRegistration;
use App\Models\StudentCourseResult;
use App\Models\StudentGradeComponent;
use App\Models\SupplementaryExamGradeEvent;
use App\Models\SupplementaryExamGradeResult;
use App\Models\SupplementaryExamGradeSubmission;
use App\Models\SupplementaryExamMaterialization;
use App\Models\SupplementaryExamMaterializationEvent;
use App\Models\SupplementaryExamOffering;
use App\Models\SupplementaryExamOfferingSource;
use App\Models\SupplementaryExamPeriod;
use App\Models\SupplementaryExamPeriodEvent;
use App\Models\SupplementaryExamRegistration;
use App\Models\User;
use App\Support\SupplementaryExamMaterializationGovernance as Governance;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lock order: period, ordered supplementary offerings, submission, ordered
 * roster/results/publish events, regular offerings/approvals/components/registrations/results,
 * then existing Phase-6 provenance. A terminal transition re-locks every prior
 * official target and approval. One transaction owns the whole offering.
 */
class SupplementaryExamMaterializationService
{
    public function __construct(
        private readonly GradeService $grades,
        private readonly DataScopeService $scope,
    ) {}

    public function decorateReviewQueue(User $actor, array $rows): array
    {
        $ready = Governance::schemaReady();
        $authorized = $actor->isExamOfficer()
            && $actor->effectivePermissions()->contains(Governance::MATERIALIZE);

        return collect($rows)->map(function (array $row) use ($actor, $ready, $authorized): array {
            $offering = $row['offering'];
            $roster = collect($row['roster'] ?? []);
            $offeringId = (int) $offering->getKey();
            $submissionId = (int) (($row['submission'] ?? null)?->getKey() ?? 0);
            $registrationIds = $roster->pluck('supplementary_exam_registration_id')->map(fn ($id) => (int) $id);
            $materializations = collect();

            if ($ready && $registrationIds->isNotEmpty()) {
                $materializations = SupplementaryExamMaterialization::query()
                    ->with([
                        'supplementaryRegistration',
                        'sourceResult.events',
                        'sourceEvent',
                        'sourceSubmission',
                        'originalRegistration.courseOffering.gradeApprovals.approvalStatus',
                        'targetResult',
                        'gradeApproval.approvalStatus',
                        'event',
                    ])
                    ->where(function ($query) use ($offeringId, $registrationIds): void {
                        $query->where('supplementary_exam_offering_id', $offeringId)
                            ->orWhereIn('supplementary_exam_registration_id', $registrationIds);
                    })
                    ->get()
                    ->keyBy('supplementary_exam_registration_id');
            }

            $row['roster'] = $roster->map(function (array $candidate) use ($materializations, $offeringId, $submissionId): array {
                $materialization = $materializations->get((int) $candidate['supplementary_exam_registration_id']);
                $candidate['official_record_materialized'] = $materialization !== null
                    && (int) $materialization->supplementary_exam_offering_id === $offeringId
                    && (int) $materialization->supplementary_exam_grade_result_id
                        === (int) ($candidate['supplementary_exam_grade_result_id'] ?? 0)
                    && (int) $materialization->supplementary_exam_grade_submission_id === $submissionId
                    && (int) $materialization->source_submission_version
                        === (int) ($candidate['submission_version'] ?? 0)
                    && $this->decimal($materialization->source_theoretical_mark)
                        === $this->decimal($candidate['supplementary_theoretical_mark'] ?? null)
                    && $this->sourceSnapshotMatches($materialization)
                    && $this->approvalSnapshotMatches($materialization)
                    && $materialization->event !== null
                    && $materialization->event->event_type === 'official_result_materialized'
                    && (int) $materialization->event->supplementary_exam_offering_id
                        === (int) $materialization->supplementary_exam_offering_id
                    && (int) $materialization->event->supplementary_exam_registration_id
                        === (int) $materialization->supplementary_exam_registration_id
                    && (int) $materialization->event->source_submission_version
                        === (int) $materialization->source_submission_version
                    && (int) $materialization->event->actor_user_id
                        === (int) $materialization->materialized_by_user_id
                    && $materialization->originalRegistration !== null
                    && $materialization->targetResult !== null
                    && $this->targetSnapshotMatches(
                        $materialization,
                        $materialization->originalRegistration,
                        $materialization->targetResult,
                    );

                return $candidate;
            })->all();

            $exactCount = collect($row['roster'])->where('official_record_materialized', true)->count();
            $periodStatus = (string) $offering->period?->status;

            if (! $ready) {
                $state = 'conflict';
                $reason = 'schema_not_ready';
            } elseif ($roster->isEmpty()) {
                $state = 'no_candidates';
                $reason = 'empty_roster';
            } elseif ($exactCount === $roster->count() && $materializations->count() === $roster->count()) {
                $state = 'materialized';
                $reason = null;
            } elseif ($materializations->isNotEmpty()) {
                $state = 'conflict';
                $reason = 'provenance_mismatch';
            } elseif (($row['workflow_status'] ?? null) === 'published') {
                $state = 'waiting';
                $reason = $periodStatus === Governance::SOURCE_PERIOD_STATUS ? null : 'period_not_published';
            } else {
                $state = 'not_ready';
                $reason = 'result_not_published';
            }

            $row['materialization'] = [
                'state' => $state,
                'reason' => $reason,
                'materialized_count' => $exactCount,
                'candidate_count' => $roster->count(),
                'can_materialize' => $state === 'waiting'
                    && $reason === null
                    && $authorized
                    && $this->scope->canMutateProgram($actor, (int) $offering->academic_program_id),
            ];

            return $row;
        })->all();
    }

    public function materializeOffering(User $actor, SupplementaryExamOffering $seed): array
    {
        $this->assertExamOfficer($actor);
        $this->assertReady();

        return DB::transaction(function () use ($actor, $seed): array {
            $seed = SupplementaryExamOffering::query()->findOrFail($seed->getKey());
            $period = SupplementaryExamPeriod::query()
                ->lockForUpdate()
                ->findOrFail($seed->supplementary_exam_period_id);
            $periodOfferings = SupplementaryExamOffering::query()
                ->where('supplementary_exam_period_id', $period->getKey())
                ->orderBy('supplementary_exam_offering_id')
                ->lockForUpdate()
                ->get();
            $offering = $periodOfferings->firstWhere('supplementary_exam_offering_id', $seed->getKey());

            if (! $offering) {
                $this->fail('The supplementary offering does not belong to the locked period.', 'supplementary_materialization_offering_mismatch', 409);
            }
            if (! in_array($period->status, [Governance::SOURCE_PERIOD_STATUS, Governance::TERMINAL_PERIOD_STATUS], true)) {
                $this->fail('Supplementary results must be published before official posting.', 'supplementary_materialization_period_not_published', 409);
            }
            if (! $this->scope->canMutateProgram($actor, (int) $offering->academic_program_id)) {
                $this->fail('The supplementary offering is outside the assigned data scope.', 'supplementary_materialization_out_of_scope', 403);
            }

            $submission = $this->lockPublishedSubmission($offering);
            [$registrations, $sourceResults] = $this->lockPublishedRoster($offering, $submission);
            $sourceEvents = $this->lockPublishedSourceEvents($sourceResults, $submission);

            $originalRegistrationIds = $registrations->pluck('student_course_registration_id')->map(fn ($id) => (int) $id);
            $regularRegistrationMap = StudentCourseRegistration::query()
                ->whereIn('student_course_registration_id', $originalRegistrationIds)
                ->pluck('course_offering_id', 'student_course_registration_id');

            if ($regularRegistrationMap->count() !== $originalRegistrationIds->count()) {
                $this->fail('An original academic registration is missing.', 'supplementary_materialization_regular_registration_missing', 409);
            }

            $sourceOfferingIds = $regularRegistrationMap->values()->map(fn ($id) => (int) $id)->unique()->sort()->values();
            $sourceOfferings = CourseOffering::query()
                ->whereIn('course_offering_id', $sourceOfferingIds)
                ->orderBy('course_offering_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('course_offering_id');

            if ($sourceOfferings->count() !== $sourceOfferingIds->count()) {
                $this->fail('A source course offering is missing.', 'supplementary_materialization_source_offering_missing', 409);
            }

            $allowedSourceIds = SupplementaryExamOfferingSource::query()
                ->where('supplementary_exam_offering_id', $offering->getKey())
                ->orderBy('supplementary_exam_offering_source_id')
                ->lockForUpdate()
                ->pluck('course_offering_id')
                ->map(fn ($id) => (int) $id);

            $approvals = GradeApproval::query()
                ->whereIn('course_offering_id', $sourceOfferingIds)
                ->orderBy('grade_approval_id')
                ->lockForUpdate()
                ->get()
                ->groupBy('course_offering_id')
                ->map(fn (Collection $rows) => $rows->last());

            $components = GradeComponent::query()
                ->whereIn('course_offering_id', $sourceOfferingIds)
                ->orderBy('grade_component_id')
                ->lockForUpdate()
                ->get()
                ->groupBy('course_offering_id');

            $regularRegistrations = StudentCourseRegistration::query()
                ->whereIn('student_course_registration_id', $originalRegistrationIds)
                ->orderBy('student_course_registration_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('student_course_registration_id');

            $regularResults = StudentCourseResult::query()
                ->whereIn('student_course_registration_id', $originalRegistrationIds)
                ->orderBy('student_course_result_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('student_course_registration_id');

            if ($regularResults->count() !== $registrations->count()) {
                $this->fail('Every candidate must have exactly one original official result.', 'supplementary_materialization_regular_result_missing', 409);
            }

            $gradeRows = StudentGradeComponent::query()
                ->whereIn('student_course_registration_id', $originalRegistrationIds)
                ->orderBy('student_grade_component_id')
                ->lockForUpdate()
                ->get()
                ->groupBy('student_course_registration_id');

            $materializations = SupplementaryExamMaterialization::query()
                ->where(function ($query) use ($offering, $registrations, $sourceResults, $sourceEvents, $regularRegistrations, $regularResults): void {
                    $query->where('supplementary_exam_offering_id', $offering->getKey())
                        ->orWhereIn('supplementary_exam_registration_id', $registrations->modelKeys())
                        ->orWhereIn('supplementary_exam_grade_result_id', $sourceResults->modelKeys())
                        ->orWhereIn('supplementary_exam_grade_event_id', $sourceEvents->pluck('supplementary_exam_grade_event_id'))
                        ->orWhereIn('student_course_registration_id', $regularRegistrations->modelKeys())
                        ->orWhereIn('student_course_result_id', $regularResults->pluck('student_course_result_id'));
                })
                ->orderBy('supplementary_exam_materialization_id')
                ->lockForUpdate()
                ->get();

            $eventsByMaterialization = SupplementaryExamMaterializationEvent::query()
                ->whereIn('supplementary_exam_materialization_id', $materializations->modelKeys())
                ->orderBy('supplementary_exam_materialization_event_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('supplementary_exam_materialization_id');

            $registrationStatuses = RegistrationStatus::query()
                ->whereIn('registration_status_id', $regularRegistrations->pluck('registration_status_id'))
                ->orderBy('registration_status_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('registration_status_id');
            $resultStatuses = ResultStatus::query()
                ->where('is_active', true)
                ->orderBy('result_status_id')
                ->lockForUpdate()
                ->get();
            $resultStatusesById = $resultStatuses->keyBy('result_status_id');
            $resultStatusesByCode = $resultStatuses->keyBy('status_code');
            foreach (['passed', 'failed'] as $requiredStatus) {
                if ($resultStatuses->where('status_code', $requiredStatus)->count() !== 1) {
                    $this->fail('A canonical result status is missing or ambiguous.', 'supplementary_materialization_result_status_missing', 409);
                }
            }

            $approvedStatuses = DB::table('approval_statuses')
                ->where('status_code', 'approved')
                ->where('is_active', true)
                ->orderBy('approval_status_id')
                ->lockForUpdate()
                ->get(['approval_status_id']);

            if ($approvedStatuses->count() !== 1) {
                $this->fail('The canonical approved status is unavailable.', 'supplementary_materialization_approval_status_missing', 409);
            }
            $approvedStatusId = (int) $approvedStatuses->first()->approval_status_id;

            $policy = $this->grades->lockDefaultGradingPolicy();
            $now = now();
            $materializedCount = 0;
            $alreadyMaterializedCount = 0;
            $matchedMaterializationIds = collect();

            foreach ($registrations as $registration) {
                $sourceResult = $sourceResults->get($registration->getKey());
                $sourceEvent = $sourceEvents->get((int) $sourceResult?->getKey());
                $regularRegistration = $regularRegistrations->get((int) $registration->student_course_registration_id);
                $regularResult = $regularResults->get((int) $registration->student_course_registration_id);
                $sourceOffering = $sourceOfferings->get((int) $regularRegistration?->course_offering_id);
                $approval = $approvals->get((int) $regularRegistration?->course_offering_id);
                $offeringComponents = $components->get((int) $regularRegistration?->course_offering_id, collect());
                $requiredComponents = $offeringComponents
                    ->where('is_required', true)
                    ->whereIn('component_type', ['theoretical', 'practical']);
                $candidateGradeRows = $gradeRows->get((int) $regularRegistration?->getKey(), collect());
                $regularRegistrationStatus = $registrationStatuses->get((int) $regularRegistration?->registration_status_id);
                $existing = $materializations->first(fn (SupplementaryExamMaterialization $row): bool =>
                    (int) $row->supplementary_exam_registration_id === (int) $registration->getKey()
                    || (int) $row->supplementary_exam_grade_result_id === (int) $sourceResult?->getKey()
                    || (int) $row->supplementary_exam_grade_event_id === (int) $sourceEvent?->getKey()
                    || (int) $row->student_course_registration_id === (int) $regularRegistration?->getKey()
                    || (int) $row->student_course_result_id === (int) $regularResult?->getKey()
                );

                $this->assertCandidateProvenance(
                    $offering,
                    $submission,
                    $registration,
                    $sourceResult,
                    $regularRegistration,
                    $regularResult,
                    $sourceOffering,
                    $allowedSourceIds,
                    $registrationStatuses,
                    $resultStatusesById,
                    $existing !== null,
                );

                if (! $approval || (int) $approval->approval_status_id !== (int) $approvedStatusId) {
                    $this->fail('The original academic result is not canonically approved.', 'supplementary_materialization_regular_result_not_approved', 409);
                }

                $practicalSnapshot = $this->practicalComponentsSnapshot($requiredComponents, $candidateGradeRows);

                if ($existing) {
                    $this->assertExistingMaterialization(
                        $existing,
                        $eventsByMaterialization->get($existing->getKey()),
                        $submission,
                        $registration,
                        $sourceResult,
                        $sourceEvent,
                        $regularRegistration,
                        $regularResult,
                        $approval,
                        $regularRegistrationStatus,
                        $practicalSnapshot,
                    );
                    $matchedMaterializationIds->push($existing->getKey());
                    $alreadyMaterializedCount++;
                    continue;
                }

                if ($period->status === Governance::TERMINAL_PERIOD_STATUS) {
                    $this->fail('A terminal period contains an unmaterialized candidate.', 'supplementary_materialization_terminal_conflict', 409);
                }

                $boundary = $this->publicationBoundary($sourceResult, $submission);
                $this->assertTargetDidNotDrift(
                    $registration,
                    $regularRegistration,
                    $regularResult,
                    $approval,
                    $offeringComponents,
                    $candidateGradeRows,
                    $boundary,
                );

                $theoreticalComponents = $requiredComponents->where('component_type', 'theoretical');
                $practicalComponents = $requiredComponents->where('component_type', 'practical');
                if ($theoreticalComponents->isEmpty()) {
                    $this->fail('The original offering has no required theoretical component.', 'supplementary_materialization_theoretical_part_missing', 409);
                }

                $theoreticalMax = (float) $theoreticalComponents->sum('max_mark');
                $practicalMax = (float) $practicalComponents->sum('max_mark');
                $theoreticalMark = (float) $sourceResult->theoretical_mark;
                $practicalMark = $practicalComponents->isEmpty() ? null : (float) $regularResult->practical_total;

                if ($theoreticalMark < 0 || $theoreticalMark > $theoreticalMax) {
                    $this->fail('The published theoretical mark is outside the canonical range.', 'supplementary_materialization_mark_out_of_range', 422);
                }
                if ($practicalMark !== null && ($practicalMark < 0 || $practicalMark > $practicalMax)) {
                    $this->fail('The preserved practical mark is outside the canonical range.', 'supplementary_materialization_practical_out_of_range', 409);
                }
                $this->assertPracticalAggregateMatchesSnapshot($practicalComponents, $practicalSnapshot, $regularResult);

                $calculation = $this->grades->buildCalculationForRequiredParts(
                    $theoreticalMark,
                    $practicalMark,
                    true,
                    $practicalComponents->isNotEmpty(),
                    $theoreticalMax,
                    $practicalMax,
                );
                $newStatus = $resultStatusesByCode->get($calculation['result_status_code']);
                if (! $newStatus) {
                    $this->fail('The canonical calculated result status is unavailable.', 'supplementary_materialization_result_status_missing', 409);
                }

                $preservedRegistrationStatusId = (int) $regularRegistration->registration_status_id;
                $before = $this->officialSnapshot($regularRegistration, $regularResult);
                $regularResult->forceFill([
                    'theoretical_total' => round($theoreticalMark, 2),
                    'final_mark' => round((float) $calculation['final_mark'], 2),
                    'result_status_id' => $newStatus->getKey(),
                    'calculated_at' => $now,
                    'calculated_by_user_id' => $actor->user_id,
                ])->save();
                $regularRegistration->forceFill(['result_status_id' => $newStatus->getKey()])->save();
                $regularResult->refresh();
                $regularRegistration->refresh();
                $after = $this->officialSnapshot($regularRegistration, $regularResult);

                $this->assertPreservedOfficialFields($before, $after);
                if ((int) $regularRegistration->registration_status_id !== $preservedRegistrationStatusId) {
                    $this->fail('The original registration status changed unexpectedly.', 'supplementary_materialization_preservation_failure', 409);
                }

                $materialization = SupplementaryExamMaterialization::query()->create(array_merge([
                    'supplementary_exam_registration_id' => $registration->getKey(),
                    'supplementary_exam_offering_id' => $offering->getKey(),
                    'supplementary_exam_grade_result_id' => $sourceResult->getKey(),
                    'supplementary_exam_grade_event_id' => $sourceEvent->getKey(),
                    'supplementary_exam_grade_submission_id' => $submission->getKey(),
                    'source_submission_version' => $submission->submission_version,
                    'student_course_registration_id' => $regularRegistration->getKey(),
                    'student_course_result_id' => $regularResult->getKey(),
                    'student_id' => $registration->student_id,
                    'grading_policy_id' => $policy->getKey(),
                    'grade_approval_id' => $approval->getKey(),
                    'preserved_registration_status_id' => $preservedRegistrationStatusId,
                    'source_theoretical_mark' => $sourceResult->theoretical_mark,
                    'practical_components_snapshot' => $practicalSnapshot,
                    'source_registration_updated_at' => $registration->updated_at,
                    'source_result_published_at' => $sourceResult->published_at,
                    'source_submission_published_at' => $submission->published_at,
                    'source_result_updated_at' => $sourceResult->updated_at,
                    'source_submission_updated_at' => $submission->updated_at,
                    'grade_approval_updated_at' => $approval->updated_at,
                    'materialized_by_user_id' => $actor->user_id,
                    'materialized_at' => $now,
                    'created_at' => $now,
                ], $this->prefixSnapshot('before', $before), $this->prefixSnapshot('after', $after)));

                SupplementaryExamMaterializationEvent::query()->create([
                    'supplementary_exam_materialization_id' => $materialization->getKey(),
                    'supplementary_exam_offering_id' => $offering->getKey(),
                    'supplementary_exam_registration_id' => $registration->getKey(),
                    'event_type' => 'official_result_materialized',
                    'source_submission_version' => $submission->submission_version,
                    'actor_user_id' => $actor->user_id,
                    'created_at' => $now,
                ]);
                $materializedCount++;
            }

            if ($matchedMaterializationIds->unique()->count() !== $materializations->count()) {
                $this->fail('Unexpected Phase-6 provenance exists for this target batch.', 'supplementary_materialization_idempotency_conflict', 409);
            }

            $periodCompleted = $this->completePeriodIfReady($period, $periodOfferings, $actor, $now);
            if ($period->status === Governance::TERMINAL_PERIOD_STATUS && ! $periodCompleted) {
                $this->fail('The terminal period no longer has complete materialization provenance.', 'supplementary_materialization_terminal_conflict', 409);
            }

            return [
                'status' => $materializedCount === 0 ? 'already_materialized' : 'materialized',
                'offering' => $offering->fresh(['period', 'course', 'academicProgram']),
                'source_submission' => [
                    'supplementary_exam_grade_submission_id' => $submission->getKey(),
                    'submission_version' => (int) $submission->submission_version,
                ],
                'candidate_count' => $registrations->count(),
                'materialized_count' => $materializedCount,
                'already_materialized_count' => $alreadyMaterializedCount,
                'period_materialized' => $periodCompleted,
                'period_status' => $period->fresh()->status,
            ];
        }, 3);
    }

    private function lockPublishedSubmission(SupplementaryExamOffering $offering): SupplementaryExamGradeSubmission
    {
        $submissions = SupplementaryExamGradeSubmission::query()
            ->where('supplementary_exam_offering_id', $offering->getKey())
            ->orderBy('supplementary_exam_grade_submission_id')
            ->lockForUpdate()
            ->get();
        $submission = $submissions->sortByDesc('submission_version')->first();

        if (! $submission || $submission->status !== 'published' || $submission->published_at === null || $submission->updated_at === null) {
            $this->fail('The latest supplementary submission is not published.', 'supplementary_materialization_submission_not_published', 409);
        }
        if ($submission->updated_at->gt($submission->published_at)) {
            $this->fail('The published supplementary submission changed after publication.', 'supplementary_materialization_source_drift', 409);
        }
        if ($submissions->where('submission_version', $submission->submission_version)->count() !== 1) {
            $this->fail('The latest supplementary submission version is ambiguous.', 'supplementary_materialization_stale_submission', 409);
        }

        return $submission;
    }

    private function lockPublishedRoster(SupplementaryExamOffering $offering, SupplementaryExamGradeSubmission $submission): array
    {
        $allRegistrations = SupplementaryExamRegistration::query()
            ->where('supplementary_exam_offering_id', $offering->getKey())
            ->orderBy('supplementary_exam_registration_id')
            ->lockForUpdate()
            ->get();
        $registrations = $allRegistrations
            ->where('status', 'registered')
            ->where('current_slot', 1)
            ->values();

        if ($registrations->isEmpty()) {
            $this->fail('The supplementary offering has no current registered candidates.', 'supplementary_materialization_empty_roster', 409);
        }

        $results = SupplementaryExamGradeResult::query()
            ->where('supplementary_exam_offering_id', $offering->getKey())
            ->orderBy('supplementary_exam_grade_result_id')
            ->lockForUpdate()
            ->get();

        $rosterIds = $registrations->pluck('supplementary_exam_registration_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $resultRosterIds = $results->pluck('supplementary_exam_registration_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        if ($rosterIds !== $resultRosterIds) {
            $this->fail('Published results do not exactly match the fixed current roster.', 'supplementary_materialization_roster_mismatch', 409);
        }

        foreach ($results as $result) {
            if ($result->status !== 'published' || $result->published_at === null || $result->updated_at === null || $result->theoretical_mark === null) {
                $this->fail('Every supplementary result must be published.', 'supplementary_materialization_result_not_published', 409);
            }
            if ($result->updated_at->gt($result->published_at)) {
                $this->fail('A published supplementary result changed after publication.', 'supplementary_materialization_source_drift', 409);
            }
            if ((int) $result->submission_version !== (int) $submission->submission_version) {
                $this->fail('A supplementary result belongs to a stale submission version.', 'supplementary_materialization_stale_submission', 409);
            }
        }

        return [$registrations, $results->keyBy('supplementary_exam_registration_id')];
    }

    private function lockPublishedSourceEvents(Collection $sourceResults, SupplementaryExamGradeSubmission $submission): Collection
    {
        $events = SupplementaryExamGradeEvent::query()
            ->whereIn('supplementary_exam_grade_result_id', $sourceResults->modelKeys())
            ->orderBy('supplementary_exam_grade_event_id')
            ->lockForUpdate()
            ->get()
            ->groupBy('supplementary_exam_grade_result_id');
        $matched = collect();

        foreach ($sourceResults as $result) {
            $resultEvents = $events->get($result->getKey(), collect());
            $published = $resultEvents->filter(fn (SupplementaryExamGradeEvent $event): bool =>
                $event->event_type === 'published'
                && $event->from_status === 'approved'
                && $event->to_status === 'published'
                && (int) $event->supplementary_exam_grade_submission_id === (int) $submission->getKey()
                && (int) $event->submission_version === (int) $submission->submission_version
            );

            if ($published->count() !== 1) {
                $this->fail('The immutable Phase-5 publication event is missing or ambiguous.', 'supplementary_materialization_source_event_mismatch', 409);
            }

            $event = $published->first();
            if ((int) $resultEvents->last()?->getKey() !== (int) $event->getKey()
                || $event->created_at === null
                || $this->decimal($event->theoretical_mark) !== $this->decimal($result->theoretical_mark)
                || $event->created_at->lt($result->published_at)
                || $event->created_at->lt($submission->published_at)) {
                $this->fail('The published Phase-5 result does not match its immutable event.', 'supplementary_materialization_source_event_mismatch', 409);
            }

            $matched->put($result->getKey(), $event);
        }

        return $matched;
    }

    private function assertCandidateProvenance(
        SupplementaryExamOffering $offering,
        SupplementaryExamGradeSubmission $submission,
        SupplementaryExamRegistration $registration,
        ?SupplementaryExamGradeResult $sourceResult,
        ?StudentCourseRegistration $regularRegistration,
        ?StudentCourseResult $regularResult,
        ?CourseOffering $sourceOffering,
        Collection $allowedSourceIds,
        Collection $registrationStatuses,
        Collection $resultStatuses,
        bool $alreadyMaterialized,
    ): void {
        if ($registration->status !== 'registered' || (int) $registration->current_slot !== 1) {
            $this->fail('The supplementary registration is not current.', 'supplementary_materialization_registration_not_current', 409);
        }
        if (! in_array($registration->eligibility_reason, ['failed_theoretical', 'voluntarily_deferred_theoretical'], true)) {
            $this->fail('The fixed roster eligibility provenance is invalid.', 'supplementary_materialization_eligibility_invalid', 409);
        }
        if (! $sourceResult
            || (int) $sourceResult->supplementary_exam_registration_id !== (int) $registration->getKey()
            || (int) $sourceResult->supplementary_exam_offering_id !== (int) $offering->getKey()
            || (int) $sourceResult->student_course_registration_id !== (int) $registration->student_course_registration_id
            || (int) $sourceResult->student_id !== (int) $registration->student_id
            || (int) $sourceResult->submission_version !== (int) $submission->submission_version) {
            $this->fail('The published supplementary result provenance is inconsistent.', 'supplementary_materialization_source_mismatch', 409);
        }
        if (! $regularRegistration || ! $regularResult || ! $sourceOffering) {
            $this->fail('The original academic attempt cannot be resolved.', 'supplementary_materialization_target_missing', 409);
        }
        if ((int) $regularRegistration->getKey() !== (int) $registration->student_course_registration_id
            || (int) $regularRegistration->student_id !== (int) $registration->student_id
            || (int) $regularResult->student_course_registration_id !== (int) $regularRegistration->getKey()
            || (int) $sourceOffering->getKey() !== (int) $regularRegistration->course_offering_id
            || (int) $sourceOffering->course_id !== (int) $offering->course_id
            || (int) $sourceOffering->academic_program_id !== (int) $offering->academic_program_id
            || ! $allowedSourceIds->contains((int) $sourceOffering->getKey())) {
            $this->fail('The original academic result does not match the supplementary source.', 'supplementary_materialization_target_mismatch', 409);
        }

        $registrationStatus = $registrationStatuses->get((int) $regularRegistration->registration_status_id)?->status_code;
        if (! in_array($registrationStatus, StudentCourseRegistration::HISTORICAL_ATTEMPT_STATUSES, true)) {
            $this->fail('The original registration is not an academic attempt.', 'supplementary_materialization_regular_registration_invalid', 409);
        }

        $resultStatus = $resultStatuses->get((int) $regularResult->result_status_id)?->status_code;
        if ((bool) $regularResult->is_deprived || $resultStatus === 'deprived') {
            $this->fail('A deprived result cannot be materialized.', 'supplementary_materialization_deprived', 409);
        }
        if (! $alreadyMaterialized
            && $regularRegistration->result_status_id !== null
            && (int) $regularRegistration->result_status_id !== (int) $regularResult->result_status_id) {
            $this->fail('The original registration and result statuses disagree.', 'supplementary_materialization_target_mismatch', 409);
        }
        if (! $alreadyMaterialized
            && $registration->eligibility_reason === 'failed_theoretical'
            && $resultStatus !== 'failed') {
            $this->fail('The original failed-result eligibility has drifted.', 'supplementary_materialization_eligibility_drift', 409);
        }
        if (! $alreadyMaterialized
            && $registration->eligibility_reason === 'voluntarily_deferred_theoretical'
            && ! in_array($resultStatus, ['incomplete', 'failed'], true)) {
            $this->fail('The deferred original result is no longer eligible.', 'supplementary_materialization_eligibility_drift', 409);
        }
    }

    private function practicalComponentsSnapshot(Collection $components, Collection $gradeRows): array
    {
        $practicalComponentIds = $components
            ->where('component_type', 'practical')
            ->pluck('grade_component_id')
            ->map(fn ($id) => (int) $id);

        return $gradeRows
            ->whereIn('grade_component_id', $practicalComponentIds)
            ->sortBy('student_grade_component_id')
            ->map(fn (StudentGradeComponent $row): array => [
                'student_grade_component_id' => (int) $row->student_grade_component_id,
                'grade_component_id' => (int) $row->grade_component_id,
                'mark' => $this->decimal($row->mark),
                'grade_status' => (string) $row->grade_status,
                'updated_at' => $this->timestamp($row->updated_at),
            ])
            ->values()
            ->all();
    }

    private function assertPracticalAggregateMatchesSnapshot(Collection $components, array $snapshot, StudentCourseResult $result): void
    {
        if ($components->isEmpty()) {
            if (abs((float) $result->practical_total) > 0.001) {
                $this->fail('A theory-only offering has an unexpected practical aggregate.', 'supplementary_materialization_practical_drift', 409);
            }
            return;
        }

        $expectedComponentIds = $components->pluck('grade_component_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $snapshotComponentIds = collect($snapshot)->pluck('grade_component_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        if ($snapshotComponentIds !== $expectedComponentIds
            || collect($snapshot)->contains(fn (array $row) => $row['mark'] === null || $row['grade_status'] !== 'approved')) {
            $this->fail('The preserved practical component evidence is incomplete.', 'supplementary_materialization_practical_components_incomplete', 409);
        }

        $sum = collect($snapshot)->sum(fn (array $row) => (float) $row['mark']);
        if (abs($sum - (float) $result->practical_total) > 0.001) {
            $this->fail('The practical aggregate no longer matches its canonical components.', 'supplementary_materialization_practical_drift', 409);
        }
    }

    private function assertTargetDidNotDrift(
        SupplementaryExamRegistration $sourceRegistration,
        StudentCourseRegistration $registration,
        StudentCourseResult $result,
        GradeApproval $approval,
        Collection $components,
        Collection $gradeRows,
        CarbonInterface $publicationBoundary,
    ): void {
        $timestamps = [
            $sourceRegistration->updated_at,
            $registration->updated_at,
            $result->updated_at,
            $approval->updated_at,
            ...$components->pluck('updated_at')->all(),
            ...$gradeRows->pluck('updated_at')->all(),
        ];

        if (collect($timestamps)->contains(fn ($value) => $value === null)) {
            $this->fail('The target does not expose a complete drift timestamp.', 'supplementary_materialization_drift_guard_unavailable', 409);
        }
        if (collect($timestamps)->contains(fn ($value) => $value->gt($publicationBoundary))) {
            $this->fail('The original academic result changed after supplementary publication.', 'supplementary_materialization_target_drift', 409);
        }
    }

    private function publicationBoundary(SupplementaryExamGradeResult $result, SupplementaryExamGradeSubmission $submission): CarbonInterface
    {
        if ($result->published_at === null || $submission->published_at === null) {
            $this->fail('Published source timestamps are required.', 'supplementary_materialization_publication_timestamp_missing', 409);
        }

        return $result->published_at->lte($submission->published_at)
            ? $result->published_at
            : $submission->published_at;
    }

    private function officialSnapshot(StudentCourseRegistration $registration, StudentCourseResult $result): array
    {
        return [
            'theoretical_total' => $this->decimal($result->theoretical_total),
            'practical_total' => $this->decimal($result->practical_total),
            'coursework_total' => $this->decimal($result->coursework_total),
            'final_mark' => $this->decimal($result->final_mark),
            'result_status_id' => (int) $result->result_status_id,
            'registration_result_status_id' => $registration->result_status_id === null ? null : (int) $registration->result_status_id,
            'is_deprived' => (bool) $result->is_deprived,
            'calculated_at' => $result->calculated_at,
            'result_announced_at' => $result->result_announced_at,
            'calculated_by_user_id' => $result->calculated_by_user_id === null ? null : (int) $result->calculated_by_user_id,
            'result_updated_at' => $result->updated_at,
            'registration_updated_at' => $registration->updated_at,
        ];
    }

    private function prefixSnapshot(string $prefix, array $snapshot): array
    {
        return collect($snapshot)->mapWithKeys(fn ($value, $key) => ["{$prefix}_{$key}" => $value])->all();
    }

    private function assertPreservedOfficialFields(array $before, array $after): void
    {
        foreach (['practical_total', 'coursework_total', 'is_deprived', 'result_announced_at'] as $field) {
            $beforeValue = str_contains($field, '_at') ? $this->timestamp($before[$field]) : $before[$field];
            $afterValue = str_contains($field, '_at') ? $this->timestamp($after[$field]) : $after[$field];
            if ($beforeValue !== $afterValue) {
                $this->fail('A protected official-result field changed unexpectedly.', 'supplementary_materialization_preservation_failure', 409);
            }
        }
    }

    private function assertExistingMaterialization(
        SupplementaryExamMaterialization $materialization,
        ?SupplementaryExamMaterializationEvent $event,
        SupplementaryExamGradeSubmission $submission,
        SupplementaryExamRegistration $registration,
        SupplementaryExamGradeResult $sourceResult,
        SupplementaryExamGradeEvent $sourceEvent,
        StudentCourseRegistration $regularRegistration,
        StudentCourseResult $regularResult,
        GradeApproval $approval,
        ?RegistrationStatus $registrationStatus,
        array $practicalSnapshot,
    ): void {
        $sourceMatches = (int) $materialization->supplementary_exam_registration_id === (int) $registration->getKey()
            && (int) $materialization->supplementary_exam_offering_id === (int) $registration->supplementary_exam_offering_id
            && (int) $materialization->supplementary_exam_grade_result_id === (int) $sourceResult->getKey()
            && (int) $materialization->supplementary_exam_grade_event_id === (int) $sourceEvent->getKey()
            && (int) $materialization->supplementary_exam_grade_submission_id === (int) $submission->getKey()
            && (int) $materialization->source_submission_version === (int) $submission->submission_version
            && (int) $materialization->student_course_registration_id === (int) $regularRegistration->getKey()
            && (int) $materialization->student_course_result_id === (int) $regularResult->getKey()
            && (int) $materialization->student_id === (int) $registration->student_id
            && (int) $materialization->grade_approval_id === (int) $approval->getKey()
            && (int) $materialization->preserved_registration_status_id === (int) $registrationStatus?->getKey()
            && $this->decimal($materialization->source_theoretical_mark) === $this->decimal($sourceResult->theoretical_mark)
            && $this->timestamp($materialization->source_registration_updated_at) === $this->timestamp($registration->updated_at)
            && $this->timestamp($materialization->source_result_published_at) === $this->timestamp($sourceResult->published_at)
            && $this->timestamp($materialization->source_submission_published_at) === $this->timestamp($submission->published_at)
            && $this->timestamp($materialization->source_result_updated_at) === $this->timestamp($sourceResult->updated_at)
            && $this->timestamp($materialization->source_submission_updated_at) === $this->timestamp($submission->updated_at)
            && $this->timestamp($materialization->grade_approval_updated_at) === $this->timestamp($approval->updated_at)
            && $materialization->practical_components_snapshot === $practicalSnapshot;

        if (! $sourceMatches) {
            $this->fail('Existing materialization points to a different published source.', 'supplementary_materialization_idempotency_conflict', 409);
        }

        if (! $this->targetSnapshotMatches($materialization, $regularRegistration, $regularResult)) {
            $this->fail('The materialized official result has changed since posting.', 'supplementary_materialization_target_conflict', 409);
        }

        if (! $event
            || $event->event_type !== 'official_result_materialized'
            || (int) $event->supplementary_exam_offering_id !== (int) $registration->supplementary_exam_offering_id
            || (int) $event->supplementary_exam_registration_id !== (int) $registration->getKey()
            || (int) $event->source_submission_version !== (int) $submission->submission_version
            || (int) $event->actor_user_id !== (int) $materialization->materialized_by_user_id) {
            $this->fail('The immutable materialization event is missing or inconsistent.', 'supplementary_materialization_event_conflict', 409);
        }
    }

    private function sourceSnapshotMatches(SupplementaryExamMaterialization $materialization): bool
    {
        $registration = $materialization->supplementaryRegistration;
        $result = $materialization->sourceResult;
        $event = $materialization->sourceEvent;
        $submission = $materialization->sourceSubmission;
        if (! $registration || ! $result || ! $event || ! $submission) {
            return false;
        }

        $latestEvent = $result->events->sortBy('supplementary_exam_grade_event_id')->last();

        return $registration->status === 'registered'
            && (int) $registration->current_slot === 1
            && (int) $registration->getKey() === (int) $materialization->supplementary_exam_registration_id
            && (int) $registration->supplementary_exam_offering_id === (int) $materialization->supplementary_exam_offering_id
            && (int) $registration->student_course_registration_id === (int) $materialization->student_course_registration_id
            && (int) $registration->student_id === (int) $materialization->student_id
            && $result->status === 'published'
            && $result->published_at !== null
            && $result->updated_at !== null
            && ! $result->updated_at->gt($result->published_at)
            && (int) $result->getKey() === (int) $materialization->supplementary_exam_grade_result_id
            && (int) $result->supplementary_exam_registration_id === (int) $registration->getKey()
            && (int) $result->supplementary_exam_offering_id === (int) $materialization->supplementary_exam_offering_id
            && (int) $result->student_course_registration_id === (int) $materialization->student_course_registration_id
            && (int) $result->student_id === (int) $materialization->student_id
            && (int) $result->submission_version === (int) $materialization->source_submission_version
            && $submission->status === 'published'
            && $submission->published_at !== null
            && $submission->updated_at !== null
            && ! $submission->updated_at->gt($submission->published_at)
            && (int) $submission->getKey() === (int) $materialization->supplementary_exam_grade_submission_id
            && (int) $submission->supplementary_exam_offering_id === (int) $materialization->supplementary_exam_offering_id
            && (int) $submission->submission_version === (int) $materialization->source_submission_version
            && (int) $event->getKey() === (int) $materialization->supplementary_exam_grade_event_id
            && (int) $latestEvent?->getKey() === (int) $event->getKey()
            && $event->event_type === 'published'
            && $event->from_status === 'approved'
            && $event->to_status === 'published'
            && (int) $event->supplementary_exam_grade_result_id === (int) $result->getKey()
            && (int) $event->supplementary_exam_grade_submission_id === (int) $submission->getKey()
            && (int) $event->submission_version === (int) $materialization->source_submission_version
            && $event->created_at !== null
            && ! $event->created_at->lt($result->published_at)
            && ! $event->created_at->lt($submission->published_at)
            && $this->decimal($event->theoretical_mark) === $this->decimal($result->theoretical_mark)
            && $this->decimal($materialization->source_theoretical_mark) === $this->decimal($result->theoretical_mark)
            && $this->timestamp($materialization->source_registration_updated_at) === $this->timestamp($registration->updated_at)
            && $this->timestamp($materialization->source_result_published_at) === $this->timestamp($result->published_at)
            && $this->timestamp($materialization->source_submission_published_at) === $this->timestamp($submission->published_at)
            && $this->timestamp($materialization->source_result_updated_at) === $this->timestamp($result->updated_at)
            && $this->timestamp($materialization->source_submission_updated_at) === $this->timestamp($submission->updated_at);
    }

    private function approvalSnapshotMatches(SupplementaryExamMaterialization $materialization): bool
    {
        $offering = $materialization->originalRegistration?->courseOffering;
        $approval = $materialization->gradeApproval;
        if (! $offering || ! $approval) {
            return false;
        }

        $latestApproval = $offering->gradeApprovals->sortBy('grade_approval_id')->last();

        return $latestApproval !== null
            && (int) $latestApproval->getKey() === (int) $approval->getKey()
            && (int) $approval->getKey() === (int) $materialization->grade_approval_id
            && $approval->approvalStatus?->status_code === 'approved'
            && (bool) $approval->approvalStatus?->is_active
            && $this->timestamp($materialization->grade_approval_updated_at) === $this->timestamp($approval->updated_at);
    }

    private function targetSnapshotMatches(
        SupplementaryExamMaterialization $materialization,
        StudentCourseRegistration $registration,
        StudentCourseResult $result,
    ): bool {
        if ((int) $registration->getKey() !== (int) $materialization->student_course_registration_id
            || (int) $registration->student_id !== (int) $materialization->student_id
            || (int) $result->getKey() !== (int) $materialization->student_course_result_id
            || (int) $result->student_course_registration_id !== (int) $registration->getKey()) {
            return false;
        }

        foreach ($this->officialSnapshot($registration, $result) as $field => $current) {
            $stored = $materialization->getAttribute("after_{$field}");
            if (in_array($field, ['theoretical_total', 'practical_total', 'coursework_total', 'final_mark'], true)) {
                $matches = $this->decimal($stored) === $this->decimal($current);
            } elseif (str_ends_with($field, '_at')) {
                $matches = $this->timestamp($stored) === $this->timestamp($current);
            } else {
                $matches = $stored === $current
                    || ($stored !== null && $current !== null && (int) $stored === (int) $current);
            }
            if (! $matches) {
                return false;
            }
        }

        return true;
    }

    private function completePeriodIfReady(
        SupplementaryExamPeriod $period,
        Collection $offerings,
        User $actor,
        CarbonInterface $now,
    ): bool {
        $offeringIds = $offerings->modelKeys();
        $registrations = SupplementaryExamRegistration::query()
            ->whereIn('supplementary_exam_offering_id', $offeringIds)
            ->where('status', 'registered')
            ->where('current_slot', 1)
            ->orderBy('supplementary_exam_registration_id')
            ->get();

        if ($registrations->isEmpty()) {
            return false;
        }

        $allResults = SupplementaryExamGradeResult::query()
            ->whereIn('supplementary_exam_offering_id', $offeringIds)
            ->orderBy('supplementary_exam_grade_result_id')
            ->get()
            ->values();
        $rosterIds = $registrations->pluck('supplementary_exam_registration_id')
            ->map(fn ($id) => (int) $id)->sort()->values()->all();
        $resultRosterIds = $allResults->pluck('supplementary_exam_registration_id')
            ->map(fn ($id) => (int) $id)->sort()->values()->all();
        if ($rosterIds !== $resultRosterIds) {
            return false;
        }
        $results = $allResults->keyBy('supplementary_exam_registration_id');
        $sourceEvents = SupplementaryExamGradeEvent::query()
            ->whereIn('supplementary_exam_grade_result_id', $allResults->modelKeys())
            ->orderBy('supplementary_exam_grade_event_id')
            ->get()
            ->groupBy('supplementary_exam_grade_result_id');
        $allMaterializations = SupplementaryExamMaterialization::query()
            ->whereIn('supplementary_exam_offering_id', $offeringIds)
            ->orderBy('supplementary_exam_materialization_id')
            ->get()
            ->values();
        $materializedRosterIds = $allMaterializations->pluck('supplementary_exam_registration_id')
            ->map(fn ($id) => (int) $id)->sort()->values()->all();
        if ($rosterIds !== $materializedRosterIds) {
            return false;
        }
        $materializations = $allMaterializations->keyBy('supplementary_exam_registration_id');
        $allEvents = SupplementaryExamMaterializationEvent::query()
            ->whereIn('supplementary_exam_materialization_id', $allMaterializations->modelKeys())
            ->orderBy('supplementary_exam_materialization_event_id')
            ->get()
            ->values();
        if ($allEvents->count() !== $allMaterializations->count()) {
            return false;
        }
        $events = $allEvents->keyBy('supplementary_exam_materialization_id');
        $submissions = SupplementaryExamGradeSubmission::query()
            ->whereIn('supplementary_exam_offering_id', $offeringIds)
            ->orderBy('supplementary_exam_grade_submission_id')
            ->get()
            ->groupBy('supplementary_exam_offering_id');

        foreach ($registrations->groupBy('supplementary_exam_offering_id') as $offeringId => $roster) {
            $offeringSubmissions = $submissions->get($offeringId, collect());
            $submission = $offeringSubmissions->sortByDesc('submission_version')->first();
            if (! $submission
                || $submission->status !== 'published'
                || $submission->published_at === null
                || $submission->updated_at === null
                || $submission->updated_at->gt($submission->published_at)
                || $offeringSubmissions->where('submission_version', $submission->submission_version)->count() !== 1) {
                return false;
            }
            foreach ($roster as $registration) {
                $result = $results->get($registration->getKey());
                $materialization = $materializations->get($registration->getKey());
                $resultEvents = $result ? $sourceEvents->get($result->getKey(), collect()) : collect();
                $sourceEvent = $materialization
                    ? $resultEvents->firstWhere('supplementary_exam_grade_event_id', $materialization->supplementary_exam_grade_event_id)
                    : null;
                $event = $materialization ? $events->get($materialization->getKey()) : null;
                if (! $result || ! $materialization
                    || ! $sourceEvent
                    || ! $event
                    || $result->status !== 'published'
                    || $result->published_at === null
                    || $result->updated_at === null
                    || $result->updated_at->gt($result->published_at)
                    || (int) $result->supplementary_exam_registration_id !== (int) $registration->getKey()
                    || (int) $result->supplementary_exam_offering_id !== (int) $offeringId
                    || (int) $result->student_course_registration_id !== (int) $registration->student_course_registration_id
                    || (int) $result->student_id !== (int) $registration->student_id
                    || (int) $result->submission_version !== (int) $submission->submission_version
                    || (int) $submission->supplementary_exam_offering_id !== (int) $offeringId
                    || (int) $materialization->supplementary_exam_registration_id !== (int) $registration->getKey()
                    || (int) $materialization->supplementary_exam_offering_id !== (int) $offeringId
                    || (int) $materialization->supplementary_exam_grade_result_id !== (int) $result->getKey()
                    || (int) $materialization->supplementary_exam_grade_submission_id !== (int) $submission->getKey()
                    || (int) $materialization->source_submission_version !== (int) $submission->submission_version
                    || (int) $materialization->student_course_registration_id !== (int) $registration->student_course_registration_id
                    || (int) $materialization->student_id !== (int) $registration->student_id
                    || $this->decimal($materialization->source_theoretical_mark) !== $this->decimal($result->theoretical_mark)
                    || $this->timestamp($materialization->source_registration_updated_at) !== $this->timestamp($registration->updated_at)
                    || $this->timestamp($materialization->source_result_published_at) !== $this->timestamp($result->published_at)
                    || $this->timestamp($materialization->source_submission_published_at) !== $this->timestamp($submission->published_at)
                    || $this->timestamp($materialization->source_result_updated_at) !== $this->timestamp($result->updated_at)
                    || $this->timestamp($materialization->source_submission_updated_at) !== $this->timestamp($submission->updated_at)
                    || (int) $resultEvents->last()?->getKey() !== (int) $sourceEvent->getKey()
                    || $sourceEvent->event_type !== 'published'
                    || $sourceEvent->from_status !== 'approved'
                    || $sourceEvent->to_status !== 'published'
                    || (int) $sourceEvent->supplementary_exam_grade_result_id !== (int) $result->getKey()
                    || (int) $sourceEvent->supplementary_exam_grade_submission_id !== (int) $submission->getKey()
                    || (int) $sourceEvent->submission_version !== (int) $submission->submission_version
                    || $this->decimal($sourceEvent->theoretical_mark) !== $this->decimal($result->theoretical_mark)
                    || $sourceEvent->created_at === null
                    || $sourceEvent->created_at->lt($result->published_at)
                    || $sourceEvent->created_at->lt($submission->published_at)
                    || $event->event_type !== 'official_result_materialized'
                    || (int) $event->supplementary_exam_materialization_id !== (int) $materialization->getKey()
                    || (int) $event->supplementary_exam_offering_id !== (int) $offeringId
                    || (int) $event->supplementary_exam_registration_id !== (int) $registration->getKey()
                    || (int) $event->source_submission_version !== (int) $submission->submission_version
                    || (int) $event->actor_user_id !== (int) $materialization->materialized_by_user_id) {
                    return false;
                }
            }
        }

        $targetRegistrationIds = $allMaterializations->pluck('student_course_registration_id');
        $targetOfferingMap = StudentCourseRegistration::query()
            ->whereIn('student_course_registration_id', $targetRegistrationIds)
            ->pluck('course_offering_id', 'student_course_registration_id');
        if ($targetOfferingMap->count() !== $allMaterializations->count()) {
            return false;
        }

        $targetOfferingIds = $targetOfferingMap->values()->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $targetOfferings = CourseOffering::query()
            ->whereIn('course_offering_id', $targetOfferingIds)
            ->orderBy('course_offering_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('course_offering_id');
        $targetApprovals = GradeApproval::query()
            ->whereIn('course_offering_id', $targetOfferingIds)
            ->orderBy('grade_approval_id')
            ->lockForUpdate()
            ->get()
            ->groupBy('course_offering_id')
            ->map(fn (Collection $rows) => $rows->last());
        $approvedStatuses = DB::table('approval_statuses')
            ->where('status_code', 'approved')
            ->where('is_active', true)
            ->orderBy('approval_status_id')
            ->lockForUpdate()
            ->get(['approval_status_id']);

        if ($targetOfferings->count() !== $targetOfferingIds->count() || $approvedStatuses->count() !== 1) {
            return false;
        }
        $approvedStatusId = (int) $approvedStatuses->first()->approval_status_id;

        $targetRegistrations = StudentCourseRegistration::query()
            ->whereIn('student_course_registration_id', $targetRegistrationIds)
            ->orderBy('student_course_registration_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('student_course_registration_id');
        $targetResults = StudentCourseResult::query()
            ->whereIn('student_course_result_id', $allMaterializations->pluck('student_course_result_id'))
            ->orderBy('student_course_result_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('student_course_result_id');

        if ($targetRegistrations->count() !== $allMaterializations->count()
            || $targetResults->count() !== $allMaterializations->count()) {
            return false;
        }

        foreach ($allMaterializations as $materialization) {
            $targetRegistration = $targetRegistrations->get((int) $materialization->student_course_registration_id);
            $targetResult = $targetResults->get((int) $materialization->student_course_result_id);
            $targetOffering = $targetOfferings->get((int) $targetRegistration?->course_offering_id);
            $targetApproval = $targetApprovals->get((int) $targetRegistration?->course_offering_id);
            if (! $targetRegistration
                || ! $targetResult
                || ! $targetOffering
                || ! $targetApproval
                || (int) $targetRegistration->student_id !== (int) $materialization->student_id
                || (int) $targetResult->student_course_registration_id !== (int) $targetRegistration->getKey()
                || (int) $targetApproval->getKey() !== (int) $materialization->grade_approval_id
                || (int) $targetApproval->approval_status_id !== $approvedStatusId
                || $this->timestamp($targetApproval->updated_at) !== $this->timestamp($materialization->grade_approval_updated_at)
                || ! $this->targetSnapshotMatches($materialization, $targetRegistration, $targetResult)) {
                return false;
            }
        }

        if ($period->status === Governance::TERMINAL_PERIOD_STATUS) {
            return true;
        }

        $from = $period->status;
        $period->forceFill(['status' => Governance::TERMINAL_PERIOD_STATUS])->save();
        SupplementaryExamPeriodEvent::query()->create([
            'supplementary_exam_period_id' => $period->getKey(),
            'event_type' => 'results_materialized',
            'from_status' => $from,
            'to_status' => Governance::TERMINAL_PERIOD_STATUS,
            'actor_user_id' => $actor->user_id,
            'notes' => json_encode([
                'roster_bearing_offerings' => $registrations->pluck('supplementary_exam_offering_id')->unique()->count(),
                'materialized_registrations' => $registrations->count(),
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
        ]);

        return true;
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

    private function assertExamOfficer(User $actor): void
    {
        if (! $actor->isExamOfficer() || ! $actor->effectivePermissions()->contains(Governance::MATERIALIZE)) {
            $this->fail('An actual Exam Officer role and assigned materialization permission are required.', 'supplementary_materialization_forbidden', 403);
        }
    }

    private function assertReady(): void
    {
        if (! Governance::schemaReady()) {
            $this->fail('The supplementary materialization schema is not ready.', 'supplementary_materialization_schema_not_ready', 503);
        }
    }

    private function fail(string $message, string $code, int $status): never
    {
        throw new GradeException($message, status: $status, errorCode: $code);
    }
}
