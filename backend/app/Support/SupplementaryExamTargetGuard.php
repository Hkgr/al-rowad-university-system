<?php

namespace App\Support;

use App\Exceptions\GradeException;
use App\Models\ApprovalStatus;
use App\Models\GradeApproval;
use App\Models\SupplementaryExamMaterialization;
use App\Models\SupplementaryExamOffering;
use App\Models\SupplementaryExamPeriod;
use App\Models\SupplementaryExamRegistration;
use App\Models\CourseOffering;
use App\Models\GradingPolicy;
use App\Models\RegistrationStatus;
use App\Models\ResultStatus;
use App\Models\StudentCourseRegistration;
use App\Models\StudentCourseResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SupplementaryExamTargetGuard
{
    public const ERROR_CODE = 'supplementary_exam_target_already_materialized';
    public const FIXED_ROSTER_ERROR_CODE = 'supplementary_fixed_roster_target_locked';
    public const CONFIGURATION_ERROR_CODE = 'supplementary_grade_configuration_locked';
    public const POLICY_ERROR_CODE = 'supplementary_grading_policy_locked';
    public const STATUS_ERROR_CODE = 'supplementary_official_status_locked';

    private const POLICY_SCORING_FIELDS = [
        'theoretical_max_mark',
        'practical_max_mark',
        'minimum_theoretical_mark',
        'minimum_practical_mark',
        'minimum_final_mark',
        'absence_deprivation_percentage',
    ];

    private const POLICY_SELECTION_FIELDS = [
        'is_default',
        'is_active',
    ];

    public static function isMaterialized(int $studentCourseRegistrationId): bool
    {
        return self::materializedTargetIds([$studentCourseRegistrationId])
            ->contains($studentCourseRegistrationId);
    }

    /** @param iterable<int|string> $studentCourseRegistrationIds
     *  @return Collection<int, int>
     */
    public static function materializedTargetIds(iterable $studentCourseRegistrationIds): Collection
    {
        $ids = collect($studentCourseRegistrationIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        if ($ids->isEmpty()) {
            return collect();
        }
        self::provenanceQueryable();

        return SupplementaryExamMaterialization::query()
            ->whereIn('student_course_registration_id', $ids)
            ->pluck('student_course_registration_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    public static function assertAvailable(int $studentCourseRegistrationId): void
    {
        self::assertAllAvailable([$studentCourseRegistrationId]);
    }

    public static function assertOrdinaryMutationAvailable(int $studentCourseRegistrationId): void
    {
        self::assertAvailable($studentCourseRegistrationId);
        self::assertFixedRosterAvailable($studentCourseRegistrationId);
    }

    public static function assertFixedRosterAvailable(int $studentCourseRegistrationId): void
    {
        self::assertFixedRosterQueryable();
        if ($studentCourseRegistrationId > 0 && SupplementaryExamRegistration::query()
            ->where('student_course_registration_id', $studentCourseRegistrationId)
            ->where('status', 'registered')
            ->where('current_slot', 1)
            ->whereHas('offering.period', fn ($period) => $period
                ->whereIn('status', SupplementaryExamRegistrationGovernance::FIXED_ROSTER_PERIOD_STATUSES))
            ->exists()) {
            throw new GradeException(
                'This regular registration belongs to a fixed supplementary-exam roster.',
                status: 409,
                errorCode: self::FIXED_ROSTER_ERROR_CODE,
            );
        }
    }

    /** @param iterable<int|string> $courseOfferingIds */
    public static function assertCourseOfferingConfigurationsMutable(iterable $courseOfferingIds): void
    {
        self::provenanceQueryable();
        self::assertFixedRosterQueryable();
        try {
            $coreReady = Schema::hasColumns('course_offerings', ['course_offering_id'])
                && Schema::hasColumns('student_course_registrations', [
                    'student_course_registration_id', 'course_offering_id',
                ]);
        } catch (Throwable) {
            $coreReady = false;
        }
        if (! $coreReady) {
            self::schemaNotReady();
        }
        $ids = collect($courseOfferingIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()->sort()->values();
        if ($ids->isEmpty()) {
            return;
        }

        $offerings = CourseOffering::query()
            ->whereIn('course_offering_id', $ids)
            ->orderBy('course_offering_id')
            ->lockForUpdate()
            ->get();
        if ($offerings->count() !== $ids->count()) {
            throw new GradeException('A course offering no longer exists.', status: 409, errorCode: self::CONFIGURATION_ERROR_CODE);
        }

        $targetIds = StudentCourseRegistration::query()
            ->whereIn('course_offering_id', $ids)
            ->orderBy('student_course_registration_id')
            ->lockForUpdate()
            ->pluck('student_course_registration_id');
        if ($targetIds->isEmpty()) {
            return;
        }

        $hasMaterialization = SupplementaryExamMaterialization::query()
            ->whereIn('student_course_registration_id', $targetIds)
            ->exists();
        $hasFixedRoster = SupplementaryExamRegistration::query()
            ->whereIn('student_course_registration_id', $targetIds)
            ->where('status', 'registered')
            ->where('current_slot', 1)
            ->whereHas('offering.period', fn ($period) => $period
                ->whereIn('status', SupplementaryExamRegistrationGovernance::FIXED_ROSTER_PERIOD_STATUSES))
            ->exists();
        if ($hasMaterialization || $hasFixedRoster) {
            throw new GradeException(
                'Grade-component configuration is locked by a fixed or materialized supplementary roster.',
                status: 409,
                errorCode: self::CONFIGURATION_ERROR_CODE,
            );
        }
    }

    public static function assertGradingPolicyCreationMutable(array $payload): void
    {
        self::assertPolicyProvenanceQueryable();
        $periods = self::lockWorkflowPeriods();
        if (self::fixedUnmaterializedTargetIds($periods)->isEmpty()) {
            return;
        }

        $policies = self::lockGradingPolicies();
        $candidate = new GradingPolicy();
        $candidate->setAttribute(
            'grading_policy_id',
            max(1, (int) $policies->max('grading_policy_id') + 1),
        );
        $candidate->fill($payload);
        $after = $policies->concat([$candidate]);
        if (((bool) $candidate->is_active && (bool) $candidate->is_default)
            || self::canonicalPolicyId($after) !== self::canonicalPolicyId($policies)) {
            self::policyLocked('This change would alter the grading policy of a fixed supplementary roster.');
        }
    }

    public static function assertGradingPolicyUpdateMutable(int $gradingPolicyId, array $payload): void
    {
        $semanticFields = array_merge(self::POLICY_SCORING_FIELDS, self::POLICY_SELECTION_FIELDS);
        if (! collect($semanticFields)->contains(fn (string $field): bool => array_key_exists($field, $payload))) {
            return;
        }

        self::assertPolicyProvenanceQueryable();
        $periods = self::lockWorkflowPeriods();
        $policies = self::lockGradingPolicies();
        $policy = $policies->firstWhere('grading_policy_id', $gradingPolicyId);
        if (! $policy) {
            self::policyLocked('The grading policy no longer exists.');
        }
        $changedFields = self::changedFields($policy, $payload, $semanticFields);
        if ($changedFields->isEmpty()) {
            return;
        }

        if (self::fixedUnmaterializedTargetIds($periods)->isNotEmpty()) {
            $beforePolicyId = self::canonicalPolicyId($policies);
            $candidatePolicies = $policies->map(function (GradingPolicy $current) use (
                $gradingPolicyId,
                $payload,
            ): GradingPolicy {
                if ((int) $current->getKey() !== $gradingPolicyId) {
                    return $current;
                }
                $candidate = clone $current;
                $candidate->fill($payload);

                return $candidate;
            });
            $afterPolicyId = self::canonicalPolicyId($candidatePolicies);
            if ($changedFields->intersect(self::POLICY_SELECTION_FIELDS)->isNotEmpty()
                && ($beforePolicyId === null || $afterPolicyId !== $beforePolicyId)) {
                self::policyLocked('This change would alter the grading policy of a fixed supplementary roster.');
            }
            if ($beforePolicyId === $gradingPolicyId
                && $changedFields->intersect(self::POLICY_SCORING_FIELDS)->isNotEmpty()) {
                self::policyLocked('The active grading rules are fixed for the current supplementary roster.');
            }
        }

        $provenanceFields = array_merge(self::POLICY_SCORING_FIELDS, ['is_active']);
        if ($changedFields->intersect($provenanceFields)->isNotEmpty()
            && self::gradingPolicyHasProvenance($gradingPolicyId)) {
            self::policyLocked('The grading policy is referenced by official supplementary provenance.');
        }
    }

    public static function assertGradingPolicyMutable(int $gradingPolicyId): void
    {
        self::assertPolicyProvenanceQueryable();
        $periods = self::lockWorkflowPeriods();
        $policies = self::lockGradingPolicies();
        if (! $policies->contains(fn (GradingPolicy $policy): bool => (int) $policy->getKey() === $gradingPolicyId)) {
            self::policyLocked('The grading policy no longer exists.');
        }
        if (self::fixedUnmaterializedTargetIds($periods)->isNotEmpty()) {
            $beforePolicyId = self::canonicalPolicyId($policies);
            $afterPolicyId = self::canonicalPolicyId($policies->reject(
                fn (GradingPolicy $policy): bool => (int) $policy->getKey() === $gradingPolicyId,
            ));
            if ($beforePolicyId === null || $afterPolicyId !== $beforePolicyId) {
                self::policyLocked('This change would alter the grading policy of a fixed supplementary roster.');
            }
        }
        if (self::gradingPolicyHasProvenance($gradingPolicyId)) {
            self::policyLocked('The grading policy is referenced by official supplementary provenance.');
        }
    }

    public static function assertResultStatusUpdateMutable(int $resultStatusId, array $payload): void
    {
        self::assertOfficialStatusUpdateMutable(
            ResultStatus::class,
            $resultStatusId,
            $payload,
            ['status_code', 'is_active'],
            fn (ResultStatus $status, Collection $targetIds): bool =>
                in_array((string) $status->status_code, ['passed', 'failed'], true)
                || StudentCourseRegistration::query()
                    ->whereIn('student_course_registration_id', $targetIds)
                    ->where('result_status_id', $resultStatusId)
                    ->exists()
                || StudentCourseResult::query()
                    ->whereIn('student_course_registration_id', $targetIds)
                    ->where('result_status_id', $resultStatusId)
                    ->exists(),
            fn (): bool => SupplementaryExamMaterialization::query()
                ->where('before_result_status_id', $resultStatusId)
                ->orWhere('before_registration_result_status_id', $resultStatusId)
                ->orWhere('after_result_status_id', $resultStatusId)
                ->orWhere('after_registration_result_status_id', $resultStatusId)
                ->exists(),
        );
    }

    public static function assertResultStatusDestroyable(int $resultStatusId): void
    {
        self::assertOfficialStatusDestroyable(
            ResultStatus::class,
            $resultStatusId,
            fn (ResultStatus $status, Collection $targetIds): bool =>
                in_array((string) $status->status_code, ['passed', 'failed'], true)
                || StudentCourseRegistration::query()
                    ->whereIn('student_course_registration_id', $targetIds)
                    ->where('result_status_id', $resultStatusId)
                    ->exists()
                || StudentCourseResult::query()
                    ->whereIn('student_course_registration_id', $targetIds)
                    ->where('result_status_id', $resultStatusId)
                    ->exists(),
            fn (): bool => SupplementaryExamMaterialization::query()
                ->where('before_result_status_id', $resultStatusId)
                ->orWhere('before_registration_result_status_id', $resultStatusId)
                ->orWhere('after_result_status_id', $resultStatusId)
                ->orWhere('after_registration_result_status_id', $resultStatusId)
                ->exists(),
            'The result status carries fixed or historical supplementary meaning.',
        );
    }

    public static function assertApprovalStatusUpdateMutable(int $approvalStatusId, array $payload): void
    {
        self::assertOfficialStatusUpdateMutable(
            ApprovalStatus::class,
            $approvalStatusId,
            $payload,
            ['status_code', 'is_active'],
            fn (ApprovalStatus $status, Collection $targetIds): bool =>
                (string) $status->status_code === 'approved'
                || self::latestTargetApprovalUsesStatus($targetIds, $approvalStatusId),
            fn (): bool => SupplementaryExamMaterialization::query()
                ->whereIn('grade_approval_id', GradeApproval::query()
                    ->select('grade_approval_id')
                    ->where('approval_status_id', $approvalStatusId))
                ->exists(),
        );
    }

    public static function assertApprovalStatusDestroyable(int $approvalStatusId): void
    {
        self::assertOfficialStatusDestroyable(
            ApprovalStatus::class,
            $approvalStatusId,
            fn (ApprovalStatus $status, Collection $targetIds): bool =>
                (string) $status->status_code === 'approved'
                || self::latestTargetApprovalUsesStatus($targetIds, $approvalStatusId),
            fn (): bool => SupplementaryExamMaterialization::query()
                ->whereIn('grade_approval_id', GradeApproval::query()
                    ->select('grade_approval_id')
                    ->where('approval_status_id', $approvalStatusId))
                ->exists(),
            'The approval status carries fixed or historical supplementary meaning.',
        );
    }

    public static function assertRegistrationStatusUpdateMutable(int $registrationStatusId, array $payload): void
    {
        self::assertOfficialStatusUpdateMutable(
            RegistrationStatus::class,
            $registrationStatusId,
            $payload,
            ['status_code'],
            fn (RegistrationStatus $status, Collection $targetIds): bool =>
                StudentCourseRegistration::query()
                    ->whereIn('student_course_registration_id', $targetIds)
                    ->where('registration_status_id', $registrationStatusId)
                    ->exists(),
            fn (): bool => SupplementaryExamMaterialization::query()
                ->where('preserved_registration_status_id', $registrationStatusId)
                ->exists(),
        );
    }

    public static function assertRegistrationStatusDestroyable(int $registrationStatusId): void
    {
        self::assertOfficialStatusDestroyable(
            RegistrationStatus::class,
            $registrationStatusId,
            fn (RegistrationStatus $status, Collection $targetIds): bool =>
                StudentCourseRegistration::query()
                    ->whereIn('student_course_registration_id', $targetIds)
                    ->where('registration_status_id', $registrationStatusId)
                    ->exists(),
            fn (): bool => SupplementaryExamMaterialization::query()
                ->where('preserved_registration_status_id', $registrationStatusId)
                ->exists(),
            'The registration status carries fixed or historical supplementary meaning.',
        );
    }

    /** @return Collection<int, GradingPolicy> */
    private static function lockGradingPolicies(): Collection
    {
        self::assertPolicyProvenanceQueryable();

        return GradingPolicy::query()
            ->orderBy('grading_policy_id')
            ->lockForUpdate()
            ->get();
    }

    private static function canonicalPolicyId(Collection $policies): ?int
    {
        $active = $policies->filter(fn (GradingPolicy $policy): bool => (bool) $policy->is_active)
            ->sortBy('grading_policy_id')
            ->values();
        $defaults = $active->filter(fn (GradingPolicy $policy): bool => (bool) $policy->is_default)->values();
        if ($active->isEmpty() || $defaults->count() > 1) {
            return null;
        }

        return (int) ($defaults->first() ?? $active->first())->getKey();
    }

    private static function gradingPolicyHasProvenance(int $gradingPolicyId): bool
    {
        return SupplementaryExamMaterialization::query()
            ->where('grading_policy_id', $gradingPolicyId)
            ->exists();
    }

    private static function assertPolicyProvenanceQueryable(): void
    {
        self::provenanceQueryable();
        try {
            $ready = Schema::hasColumns('supplementary_exam_materializations', ['grading_policy_id'])
                && Schema::hasColumns('grading_policies', ['grading_policy_id']);
        } catch (Throwable) {
            $ready = false;
        }
        if (! $ready) {
            self::schemaNotReady();
        }
    }

    private static function lockWorkflowPeriods(): Collection
    {
        self::provenanceQueryable();
        self::assertFixedRosterQueryable();
        $statuses = array_values(array_unique(array_merge(
            ['registration_open'],
            array_values(array_diff(
                SupplementaryExamRegistrationGovernance::FIXED_ROSTER_PERIOD_STATUSES,
                ['results_materialized'],
            )),
        )));

        return SupplementaryExamPeriod::query()
            ->whereIn('status', $statuses)
            ->orderBy('supplementary_exam_period_id')
            ->lockForUpdate()
            ->get(['supplementary_exam_period_id', 'status']);
    }

    /** @return Collection<int, int> */
    private static function fixedUnmaterializedTargetIds(Collection $periods): Collection
    {
        try {
            if (! Schema::hasColumns('supplementary_exam_materializations', [
                'supplementary_exam_registration_id',
            ])) {
                self::schemaNotReady();
            }
        } catch (GradeException $exception) {
            throw $exception;
        } catch (Throwable) {
            self::schemaNotReady();
        }

        $fixedPeriodIds = $periods
            ->whereIn('status', SupplementaryExamRegistrationGovernance::FIXED_ROSTER_PERIOD_STATUSES)
            ->pluck('supplementary_exam_period_id');
        if ($fixedPeriodIds->isEmpty()) {
            return collect();
        }

        $offeringIds = SupplementaryExamOffering::query()
            ->whereIn('supplementary_exam_period_id', $fixedPeriodIds)
            ->pluck('supplementary_exam_offering_id');
        if ($offeringIds->isEmpty()) {
            return collect();
        }

        return SupplementaryExamRegistration::query()
            ->whereIn('supplementary_exam_offering_id', $offeringIds)
            ->where('status', 'registered')
            ->where('current_slot', 1)
            ->whereDoesntHave('materialization')
            ->pluck('student_course_registration_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
    }

    /** @param class-string<Model> $modelClass */
    private static function assertOfficialStatusUpdateMutable(
        string $modelClass,
        int $statusId,
        array $payload,
        array $semanticFields,
        callable $hasFixedDependency,
        callable $hasProvenance,
    ): void {
        if (! collect($semanticFields)->contains(fn (string $field): bool => array_key_exists($field, $payload))) {
            return;
        }

        $periods = self::lockWorkflowPeriods();
        $status = self::lockOfficialStatus($modelClass, $statusId);
        if (self::changedFields($status, $payload, $semanticFields)->isEmpty()) {
            return;
        }
        $targetIds = self::fixedUnmaterializedTargetIds($periods);
        if ($targetIds->isNotEmpty() && $hasFixedDependency($status, $targetIds)) {
            self::statusLocked('The status carries academic meaning for a fixed supplementary roster.');
        }
        if ($hasProvenance()) {
            self::statusLocked('The status is referenced by official supplementary provenance.');
        }
    }

    /** @param class-string<Model> $modelClass */
    private static function assertOfficialStatusDestroyable(
        string $modelClass,
        int $statusId,
        callable $hasFixedDependency,
        callable $hasProvenance,
        string $message,
    ): void {
        $periods = self::lockWorkflowPeriods();
        $status = self::lockOfficialStatus($modelClass, $statusId);
        $targetIds = self::fixedUnmaterializedTargetIds($periods);
        if (($targetIds->isNotEmpty() && $hasFixedDependency($status, $targetIds)) || $hasProvenance()) {
            self::statusLocked($message);
        }
    }

    private static function latestTargetApprovalUsesStatus(Collection $targetIds, int $approvalStatusId): bool
    {
        $offeringIds = StudentCourseRegistration::query()
            ->whereIn('student_course_registration_id', $targetIds)
            ->pluck('course_offering_id');
        if ($offeringIds->isEmpty()) {
            return false;
        }

        return GradeApproval::query()
            ->whereIn('course_offering_id', $offeringIds)
            ->where('approval_status_id', $approvalStatusId)
            ->whereNotExists(fn ($newer) => $newer
                ->selectRaw('1')
                ->from('grade_approvals as newer')
                ->whereColumn('newer.course_offering_id', 'grade_approvals.course_offering_id')
                ->whereColumn('newer.grade_approval_id', '>', 'grade_approvals.grade_approval_id'))
            ->exists();
    }

    /** @param class-string<Model> $modelClass */
    private static function lockOfficialStatus(string $modelClass, int $statusId): Model
    {
        self::assertOfficialStatusProvenanceQueryable();
        $status = $modelClass::query()->whereKey($statusId)->lockForUpdate()->first();
        if (! $status) {
            self::statusLocked('The official status no longer exists.');
        }

        return $status;
    }

    private static function assertOfficialStatusProvenanceQueryable(): void
    {
        self::provenanceQueryable();
        try {
            $ready = Schema::hasColumns('supplementary_exam_materializations', [
                'grading_policy_id',
                'grade_approval_id',
                'preserved_registration_status_id',
                'before_result_status_id',
                'before_registration_result_status_id',
                'after_result_status_id',
                'after_registration_result_status_id',
            ])
                && Schema::hasColumns('grade_approvals', ['grade_approval_id', 'approval_status_id'])
                && Schema::hasColumns('result_statuses', ['result_status_id', 'status_code', 'is_active'])
                && Schema::hasColumns('approval_statuses', ['approval_status_id', 'status_code', 'is_active'])
                && Schema::hasColumns('registration_statuses', ['registration_status_id', 'status_code']);
        } catch (Throwable) {
            $ready = false;
        }
        if (! $ready) {
            self::schemaNotReady();
        }
    }

    private static function changedFields(Model $model, array $payload, array $fields): Collection
    {
        $candidate = clone $model;
        $candidate->fill($payload);

        return collect($fields)
            ->filter(fn (string $field): bool => array_key_exists($field, $payload) && $candidate->isDirty($field))
            ->values();
    }

    private static function policyLocked(string $message): never
    {
        throw new GradeException($message, status: 409, errorCode: self::POLICY_ERROR_CODE);
    }

    private static function statusLocked(string $message): never
    {
        throw new GradeException($message, status: 409, errorCode: self::STATUS_ERROR_CODE);
    }

    /** @param iterable<int|string> $studentCourseRegistrationIds */
    public static function assertAllAvailable(iterable $studentCourseRegistrationIds): void
    {
        self::provenanceQueryable();
        $ids = collect($studentCourseRegistrationIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        if ($ids->isNotEmpty() && SupplementaryExamMaterialization::query()
            ->whereIn('student_course_registration_id', $ids)
            ->exists()) {
            throw new GradeException(
                'This regular registration already has an officially materialized supplementary result.',
                status: 409,
                errorCode: self::ERROR_CODE,
            );
        }
    }

    private static function provenanceQueryable(): bool
    {
        if (! SupplementaryExamMaterializationGovernance::materializationTableAvailable()) {
            self::schemaNotReady();
        }

        try {
            if (Schema::hasColumn('supplementary_exam_materializations', 'student_course_registration_id')) {
                return true;
            }
        } catch (Throwable) {
            // The stable domain error below is safer than leaking a driver/schema exception.
        }

        self::schemaNotReady();
    }

    private static function assertFixedRosterQueryable(): void
    {
        try {
            $ready = Schema::hasColumns('supplementary_exam_registrations', [
                'student_course_registration_id', 'status', 'current_slot', 'supplementary_exam_offering_id',
            ])
                && Schema::hasColumns('supplementary_exam_offerings', [
                    'supplementary_exam_offering_id', 'supplementary_exam_period_id',
                ])
                && Schema::hasColumns('supplementary_exam_periods', [
                    'supplementary_exam_period_id', 'status',
                ]);
            if ($ready) {
                return;
            }
        } catch (Throwable) {
            // Convert schema/driver failures to the stable domain error below.
        }

        self::schemaNotReady();
    }

    private static function schemaNotReady(): never
    {
        throw new GradeException(
            'The supplementary materialization provenance contract is incomplete.',
            status: 503,
            errorCode: 'supplementary_materialization_schema_not_ready',
        );
    }
}
