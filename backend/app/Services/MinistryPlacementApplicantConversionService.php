<?php

namespace App\Services;

use App\Exceptions\MinistryPlacementException;
use App\Models\AdmissionApplication;
use App\Models\Applicant;
use App\Models\MinistryPlacementBatch;
use App\Models\MinistryPlacementRecord;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Support\MinistryPlacementNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MinistryPlacementApplicantConversionService
{
    private const PENDING_DECISION = 'pending';

    private const LATER_DECISIONS = ['accepted', 'rejected'];

    private const LATER_PROCESSING_STATUSES = ['documents_pending', 'accepted', 'enrolled', 'rejected'];

    private const CONVERSION_PROCESSING_STATUSES = ['applicant_created', 'documents_pending', 'accepted', 'enrolled', 'rejected'];

    /** @return array<string, mixed> */
    public function summary(int $batchId): array
    {
        $batch = MinistryPlacementBatch::query()->with('academicYear')->findOrFail($batchId);
        $records = $this->recordsQuery($batchId)->get();
        $analysis = $this->analyse($batch, $records);

        return $this->summaryPayload($batch, $analysis);
    }

    /** @return array<string, mixed> */
    public function convert(int $recordId, User $actor): array
    {
        return DB::transaction(function () use ($recordId, $actor): array {
            $record = MinistryPlacementRecord::query()->lockForUpdate()->findOrFail($recordId);
            $batch = MinistryPlacementBatch::query()->with('academicYear')->findOrFail((int) $record->batch_id);
            $record->load($this->recordRelations());
            $analysis = $this->analyse($batch, collect([$record]), true);
            $item = $analysis['items']->sole();

            if (in_array($item['conversion_state'], ['converted', 'later_stage'], true)) {
                return ['created' => false, 'conversion' => $this->recordPayload($item, $batch)];
            }
            if ($item['conversion_state'] !== 'convertible') {
                $this->throwForBlocker($item);
            }

            $applicationDate = CarbonImmutable::now('UTC')->toDateString();
            [$applicant, $application] = $this->createConversionRows($record, $batch, $applicationDate);
            $record->forceFill([
                'applicant_id' => (int) $applicant->applicant_id,
                'processing_status' => 'applicant_created',
            ])->save();

            $this->audit($actor, 'ministry_placement.applicant_convert', [
                'record_id' => (int) $record->placement_record_id,
                'batch_id' => (int) $record->batch_id,
                'applicant_id' => (int) $applicant->applicant_id,
                'admission_application_id' => (int) $application->admission_application_id,
                'academic_program_id' => (int) $record->matched_academic_program_id,
                'academic_year_id' => (int) $batch->academic_year_id,
            ]);

            $record = $this->recordsQuery((int) $batch->batch_id)
                ->where('placement_record_id', (int) $record->placement_record_id)
                ->firstOrFail();
            $createdItem = $this->analyse($batch, collect([$record]), true)['items']->sole();

            return ['created' => true, 'conversion' => $this->recordPayload($createdItem, $batch)];
        });
    }

    /** @return array<string, mixed> */
    public function convertAll(int $batchId, int $expectedEligibleCount, string $expectedSnapshot, User $actor): array
    {
        return DB::transaction(function () use ($batchId, $expectedEligibleCount, $expectedSnapshot, $actor): array {
            $batch = MinistryPlacementBatch::query()->with('academicYear')->lockForUpdate()->findOrFail($batchId);
            $records = MinistryPlacementRecord::query()
                ->where('batch_id', $batchId)
                ->orderBy('placement_record_id')
                ->lockForUpdate()
                ->get();
            $records->load($this->recordRelations());
            $analysis = $this->analyse($batch, $records);
            $eligible = $analysis['items']->where('conversion_state', 'convertible')->values();
            $snapshot = $this->snapshot($eligible, (int) $batch->academic_year_id);

            if ($eligible->count() !== $expectedEligibleCount || ! hash_equals($snapshot, $expectedSnapshot)) {
                throw MinistryPlacementException::conversionConflict(
                    'ministry_placement_conversion_batch_stale',
                    'تغيرت السجلات الجاهزة للتحويل. حدّث البيانات ثم أكّد العملية مجدداً.',
                );
            }

            $applicationDate = CarbonImmutable::now('UTC')->toDateString();
            foreach ($eligible as $item) {
                /** @var MinistryPlacementRecord $record */
                $record = $item['record'];
                [$applicant] = $this->createConversionRows($record, $batch, $applicationDate);
                $record->forceFill([
                    'applicant_id' => (int) $applicant->applicant_id,
                    'processing_status' => 'applicant_created',
                ])->save();
            }

            if ($eligible->isNotEmpty()) {
                $this->audit($actor, 'ministry_placement.applicant_convert_bulk', [
                    'batch_id' => (int) $batch->batch_id,
                    'academic_year_id' => (int) $batch->academic_year_id,
                    'converted_count' => $eligible->count(),
                ]);
            }

            return [
                'batch_id' => (int) $batch->batch_id,
                'academic_year_id' => (int) $batch->academic_year_id,
                'converted_count' => $eligible->count(),
            ];
        });
    }

    private function recordsQuery(int $batchId): Builder
    {
        return MinistryPlacementRecord::query()
            ->where('batch_id', $batchId)
            ->with($this->recordRelations())
            ->orderBy('placement_record_id');
    }

    /** @return array<int, string> */
    private function recordRelations(): array
    {
        return [
            'matchedAcademicProgram.department.college',
            'applicant',
        ];
    }

    /** @return array{items: Collection<int, array<string, mixed>>, identity_conflict_records: int} */
    private function analyse(MinistryPlacementBatch $batch, Collection $records, bool $preciseLinkedReplay = false): array
    {
        $identityRows = MinistryPlacementRecord::query()
            ->select(['placement_record_id', 'batch_id', 'national_civil_id', 'applicant_id'])
            ->orderBy('placement_record_id')
            ->get();
        $identities = [];
        foreach ($identityRows as $identityRow) {
            $key = MinistryPlacementNormalizer::duplicateKey($identityRow->national_civil_id);
            if ($key !== null) {
                $identities[$key][] = [
                    'placement_record_id' => (int) $identityRow->placement_record_id,
                    'batch_id' => (int) $identityRow->batch_id,
                    'applicant_id' => $identityRow->applicant_id === null ? null : (int) $identityRow->applicant_id,
                ];
            }
        }

        $numbers = $records->map(fn (MinistryPlacementRecord $record): string => $this->applicantNumber($record))->unique()->values();
        $numberOwners = Applicant::query()
            ->whereIn('applicant_number', $numbers->all())
            ->get(['applicant_id', 'applicant_number'])
            ->keyBy('applicant_number');

        $linkedApplicantIds = $records->pluck('applicant_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        $applications = collect();
        if ($linkedApplicantIds->isNotEmpty()) {
            $applicationQuery = AdmissionApplication::query()->whereIn('applicant_id', $linkedApplicantIds->all());
            if ($preciseLinkedReplay && $records->count() === 1) {
                /** @var MinistryPlacementRecord $linkedRecord */
                $linkedRecord = $records->sole();
                $applicationQuery
                    ->where('applicant_id', (int) $linkedRecord->applicant_id)
                    ->where('academic_program_id', (int) $linkedRecord->matched_academic_program_id)
                    ->where('academic_year_id', (int) $batch->academic_year_id);
            }
            $applications = $applicationQuery->orderBy('admission_application_id')->get();
        }

        $items = $records->map(function (MinistryPlacementRecord $record) use ($batch, $identities, $numberOwners, $applications): array {
            $identityKey = MinistryPlacementNormalizer::duplicateKey($record->national_civil_id);
            $identityReferences = $identityKey === null ? [] : ($identities[$identityKey] ?? []);
            $identityConflict = count($identityReferences) > 1;
            $base = [
                'record' => $record,
                'conversion_state' => 'inconsistent',
                'blocker_code' => null,
                'application' => null,
                'identity_conflict' => $identityConflict,
                'identity_conflicts' => $identityConflict ? array_values(array_filter(
                    $identityReferences,
                    fn (array $reference): bool => $reference['placement_record_id'] !== (int) $record->placement_record_id,
                )) : [],
            ];

            if ($record->applicant_id !== null) {
                $expectedApplications = $applications->filter(fn (AdmissionApplication $application): bool =>
                    (int) $application->applicant_id === (int) $record->applicant_id
                    && (int) $application->academic_program_id === (int) $record->matched_academic_program_id
                    && (int) $application->academic_year_id === (int) $batch->academic_year_id)->values();

                return $this->analyseLinkedRecord($record, $batch, $numberOwners, $expectedApplications, $base);
            }

            if (in_array((string) $record->processing_status, self::CONVERSION_PROCESSING_STATUSES, true)) {
                return array_replace($base, ['blocker_code' => 'conversion_link_missing']);
            }
            if (! in_array((string) $record->processing_status, ['imported', 'program_matched'], true)) {
                return array_replace($base, ['blocker_code' => 'conversion_status_inconsistent']);
            }

            $programState = $record->programMatchState();
            if ($programState !== 'matched') {
                return array_replace($base, [
                    'conversion_state' => 'not_ready',
                    'blocker_code' => $programState === 'stale_match' ? 'program_match_stale' : 'program_not_matched',
                ]);
            }
            if ($identityKey === null) {
                return array_replace($base, ['blocker_code' => 'identity_missing']);
            }
            if ($identityConflict) {
                return array_replace($base, ['blocker_code' => 'identity_conflict']);
            }
            if (! $this->applicantDataIsValid($record)) {
                return array_replace($base, ['blocker_code' => 'applicant_data_invalid']);
            }
            if ($batch->academicYear === null) {
                return array_replace($base, ['blocker_code' => 'academic_year_missing']);
            }

            $number = $this->applicantNumber($record);
            if (mb_strlen($number, 'UTF-8') > 50 || $numberOwners->has($number)) {
                return array_replace($base, ['blocker_code' => 'applicant_number_conflict']);
            }

            return array_replace($base, ['conversion_state' => 'convertible']);
        })->values();

        return [
            'items' => $items,
            'identity_conflict_records' => $items->where('identity_conflict', true)->count(),
        ];
    }

    /** @param Collection<string, Applicant> $numberOwners @param array<string, mixed> $base @return array<string, mixed> */
    private function analyseLinkedRecord(MinistryPlacementRecord $record, MinistryPlacementBatch $batch, Collection $numberOwners, Collection $applications, array $base): array
    {
        $applicant = $record->applicant;
        if ($applicant === null) {
            return array_replace($base, ['blocker_code' => 'linked_applicant_missing']);
        }
        $expectedNumber = $this->applicantNumber($record);
        $numberOwner = $numberOwners->get($expectedNumber);
        if ($applicant->applicant_number !== $expectedNumber
            || ($numberOwner !== null && (int) $numberOwner->applicant_id !== (int) $applicant->applicant_id)) {
            return array_replace($base, ['blocker_code' => 'applicant_number_conflict']);
        }
        if ($record->matched_academic_program_id === null || $batch->academicYear === null) {
            return array_replace($base, ['blocker_code' => 'application_context_mismatch']);
        }

        if ($applications->count() !== 1) {
            return array_replace($base, ['blocker_code' => $applications->isEmpty() ? 'expected_application_missing' : 'expected_application_ambiguous']);
        }

        /** @var AdmissionApplication $application */
        $application = $applications->sole();
        $processingStatus = (string) $record->processing_status;
        $decisionStatus = trim((string) $application->decision_status);
        if (! in_array($processingStatus, self::CONVERSION_PROCESSING_STATUSES, true)) {
            return array_replace($base, ['blocker_code' => 'conversion_status_inconsistent', 'application' => $application]);
        }
        if ($decisionStatus === self::PENDING_DECISION
            && ($application->decision_date !== null || $application->decided_by_user_id !== null)) {
            return array_replace($base, ['blocker_code' => 'decision_provenance_inconsistent', 'application' => $application]);
        }
        if (in_array($decisionStatus, self::LATER_DECISIONS, true)
            && ($application->decision_date === null || $application->decided_by_user_id === null)) {
            return array_replace($base, ['blocker_code' => 'decision_provenance_inconsistent', 'application' => $application]);
        }
        if ($decisionStatus === self::PENDING_DECISION && $processingStatus === 'applicant_created') {
            return array_replace($base, ['conversion_state' => 'converted', 'application' => $application]);
        }
        if (in_array($decisionStatus, self::LATER_DECISIONS, true)
            || in_array($processingStatus, self::LATER_PROCESSING_STATUSES, true)) {
            if ($decisionStatus === self::PENDING_DECISION || in_array($decisionStatus, self::LATER_DECISIONS, true)) {
                return array_replace($base, ['conversion_state' => 'later_stage', 'application' => $application]);
            }
        }

        return array_replace($base, ['blocker_code' => 'decision_status_unsupported', 'application' => $application]);
    }

    /** @param array{items: Collection<int, array<string, mixed>>, identity_conflict_records: int} $analysis @return array<string, mixed> */
    private function summaryPayload(MinistryPlacementBatch $batch, array $analysis): array
    {
        $items = $analysis['items'];
        $eligible = $items->where('conversion_state', 'convertible')->values();

        return [
            'batch_id' => (int) $batch->batch_id,
            'academic_year_id' => (int) $batch->academic_year_id,
            'academic_year' => $batch->academicYear === null ? null : [
                'academic_year_id' => (int) $batch->academicYear->academic_year_id,
                'year_name' => $batch->academicYear->year_name,
            ],
            'metrics' => [
                'total_records' => $items->count(),
                'not_ready_records' => $items->where('conversion_state', 'not_ready')->count(),
                'convertible_records' => $eligible->count(),
                'converted_records' => $items->where('conversion_state', 'converted')->count(),
                'inconsistent_records' => $items->where('conversion_state', 'inconsistent')->count(),
                'later_stage_records' => $items->where('conversion_state', 'later_stage')->count(),
                'identity_conflict_records' => $analysis['identity_conflict_records'],
            ],
            'eligible_count' => $eligible->count(),
            'eligible_snapshot' => $this->snapshot($eligible, (int) $batch->academic_year_id),
            'records' => $items->map(fn (array $item): array => $this->recordPayload($item, $batch))->all(),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $eligible */
    private function snapshot(Collection $eligible, int $academicYearId): string
    {
        $canonical = $eligible
            ->sortBy(fn (array $item): int => (int) $item['record']->placement_record_id)
            ->map(fn (array $item): string => implode(':', [
                (int) $item['record']->placement_record_id,
                (int) $item['record']->matched_academic_program_id,
                $academicYearId,
            ]))
            ->implode("\n");

        return hash('sha256', $canonical);
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function recordPayload(array $item, MinistryPlacementBatch $batch): array
    {
        /** @var MinistryPlacementRecord $record */
        $record = $item['record'];
        $program = $record->matchedAcademicProgram;
        $applicant = $record->applicant;
        /** @var AdmissionApplication|null $application */
        $application = $item['application'];

        return [
            'placement_record_id' => (int) $record->placement_record_id,
            'row_number' => $record->row_number,
            'full_name' => $record->full_name,
            'processing_status' => $record->processing_status,
            'conversion_state' => $item['conversion_state'],
            'blocker_code' => $item['blocker_code'],
            'identity_conflict' => $item['identity_conflict'],
            'identity_conflicts' => $item['identity_conflicts'],
            'academic_program' => $program === null ? null : [
                'academic_program_id' => (int) $program->academic_program_id,
                'program_code' => $program->program_code,
                'program_name' => $program->program_name,
            ],
            'academic_year' => $batch->academicYear === null ? null : [
                'academic_year_id' => (int) $batch->academicYear->academic_year_id,
                'year_name' => $batch->academicYear->year_name,
            ],
            'applicant' => $applicant === null ? null : [
                'applicant_id' => (int) $applicant->applicant_id,
                'applicant_number' => $applicant->applicant_number,
            ],
            'admission_application' => $application === null ? null : [
                'admission_application_id' => (int) $application->admission_application_id,
                'decision_status' => $application->decision_status,
                'application_date' => $application->application_date?->toDateString(),
            ],
        ];
    }

    /** @return array{0: Applicant, 1: AdmissionApplication} */
    private function createConversionRows(MinistryPlacementRecord $record, MinistryPlacementBatch $batch, string $applicationDate): array
    {
        try {
            $applicant = Applicant::query()->create([
                'applicant_number' => $this->applicantNumber($record),
                'first_name' => $record->first_name,
                'last_name' => $record->last_name,
                'father_name' => $record->father_name,
                'mother_name' => $record->mother_name,
                'date_of_birth' => $record->date_of_birth?->toDateString(),
                'gender' => $record->gender,
                'phone_number' => $record->phone_number,
                'email' => $record->email,
                'address' => null,
                'nationality' => $record->nationality,
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw MinistryPlacementException::conversionConflict(
                    'ministry_placement_applicant_number_conflict',
                    'رقم المتقدم الحتمي مستخدم مسبقاً ولا يمكن اعتماد سجل آخر تلقائياً.',
                );
            }
            throw $exception;
        }

        $application = AdmissionApplication::query()->create([
            'applicant_id' => (int) $applicant->applicant_id,
            'academic_program_id' => (int) $record->matched_academic_program_id,
            'academic_year_id' => (int) $batch->academic_year_id,
            'application_date' => $applicationDate,
            'decision_status' => self::PENDING_DECISION,
            'decision_date' => null,
            'decided_by_user_id' => null,
            'notes' => null,
        ]);

        return [$applicant, $application];
    }

    /** @param array<string, mixed> $item */
    private function throwForBlocker(array $item): never
    {
        $code = match ($item['blocker_code']) {
            'identity_missing' => 'ministry_placement_identity_missing',
            'identity_conflict' => 'ministry_placement_identity_conflict',
            'program_match_stale' => 'ministry_placement_program_match_stale',
            'applicant_number_conflict' => 'ministry_placement_applicant_number_conflict',
            default => $item['conversion_state'] === 'not_ready'
                ? 'ministry_placement_conversion_not_ready'
                : 'ministry_placement_conversion_inconsistent',
        };
        $message = match ($code) {
            'ministry_placement_identity_missing' => 'لا يمكن التحويل دون هوية وزارة محددة.',
            'ministry_placement_identity_conflict' => 'هوية الوزارة مكررة في سجل مفاضلة آخر وتحتاج إلى مصالحة صريحة.',
            'ministry_placement_program_match_stale' => 'مطابقة البرنامج لم تعد نشطة ويجب تصحيحها قبل التحويل.',
            'ministry_placement_applicant_number_conflict' => 'رقم المتقدم الحتمي مستخدم مسبقاً ولا يمكن اعتماد سجل آخر تلقائياً.',
            'ministry_placement_conversion_not_ready' => 'سجل المفاضلة غير جاهز للتحويل إلى متقدم.',
            default => 'حالة تحويل سجل المفاضلة غير متسقة وتحتاج إلى مراجعة.',
        };
        $errors = $code === 'ministry_placement_identity_conflict'
            ? ['conflicts' => $item['identity_conflicts']]
            : ['blocker_code' => [$item['blocker_code']]];

        throw MinistryPlacementException::conversionConflict($code, $message, $errors);
    }

    private function applicantNumber(MinistryPlacementRecord $record): string
    {
        return 'MP-R'.(int) $record->placement_record_id;
    }

    private function applicantDataIsValid(MinistryPlacementRecord $record): bool
    {
        return $this->requiredTextFits($record->first_name, 100)
            && $this->requiredTextFits($record->last_name, 100)
            && $this->nullableTextFits($record->father_name, 100)
            && $this->nullableTextFits($record->mother_name, 100)
            && $this->nullableTextFits($record->gender, 20)
            && $this->nullableTextFits($record->phone_number, 30)
            && $this->nullableTextFits($record->email, 150)
            && $this->nullableTextFits($record->nationality, 100);
    }

    private function requiredTextFits(mixed $value, int $maximum): bool
    {
        $text = MinistryPlacementNormalizer::text($value);

        return $text !== null && mb_strlen($text, 'UTF-8') <= $maximum;
    }

    private function nullableTextFits(mixed $value, int $maximum): bool
    {
        $text = MinistryPlacementNormalizer::text($value);

        return $text === null || mb_strlen($text, 'UTF-8') <= $maximum;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062
            || (string) ($exception->errorInfo[0] ?? '') === '23000';
    }

    /** @param array<string, mixed> $description */
    private function audit(User $actor, string $action, array $description): void
    {
        UserActivityLog::query()->create([
            'user_id' => (int) $actor->user_id,
            'module_code' => 'admissions',
            'action_code' => $action,
            'description' => json_encode($description, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }
}
