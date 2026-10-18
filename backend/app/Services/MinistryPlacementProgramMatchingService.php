<?php

namespace App\Services;

use App\Exceptions\MinistryPlacementException;
use App\Models\AcademicProgram;
use App\Models\MinistryPlacementBatch;
use App\Models\MinistryPlacementRecord;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Support\AcademicQueuePagination;
use App\Support\MinistryProgramMatcher;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MinistryPlacementProgramMatchingService
{
    public function __construct(private readonly MinistryProgramMatcher $matcher) {}

    /** @param array<string, mixed> $filters */
    public function programs(array $filters): LengthAwarePaginator
    {
        $query = $this->activeProgramsQuery();
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where(function (Builder $candidate) use ($escaped): void {
                $candidate->where('program_code', 'like', '%'.$escaped.'%')
                    ->orWhere('program_name', 'like', '%'.$escaped.'%')
                    ->orWhereHas('department', fn (Builder $department) => $department
                        ->where('department_code', 'like', '%'.$escaped.'%')
                        ->orWhere('department_name', 'like', '%'.$escaped.'%')
                        ->orWhereHas('college', fn (Builder $college) => $college
                            ->where('college_code', 'like', '%'.$escaped.'%')
                            ->orWhere('college_name', 'like', '%'.$escaped.'%')));
            });
        }
        if (isset($filters['college_id'])) {
            $collegeId = (int) $filters['college_id'];
            $query->whereHas('department', fn (Builder $department) => $department->where('college_id', $collegeId));
        }

        $paginator = $query->orderBy('program_name')->orderBy('academic_program_id')
            ->paginate(AcademicQueuePagination::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 15));
        $paginator->through(fn (AcademicProgram $program): array => $this->programPayload($program));

        return $paginator;
    }

    /** @return array<string, mixed> */
    public function summary(int $batchId): array
    {
        $batch = MinistryPlacementBatch::query()->findOrFail($batchId);
        $records = MinistryPlacementRecord::query()
            ->where('batch_id', $batchId)
            ->with('matchedAcademicProgram.department.college')
            ->orderBy('placement_record_id')
            ->get();
        $catalog = $this->activeProgramsQuery()
            ->orderBy('program_name')
            ->orderBy('academic_program_id')
            ->get()
            ->map(fn (AcademicProgram $program): array => $this->programPayload($program))
            ->all();

        $states = $records->mapWithKeys(fn (MinistryPlacementRecord $record): array => [
            (int) $record->placement_record_id => $record->programMatchState(),
        ]);
        $groups = $records->groupBy(fn (MinistryPlacementRecord $record): string => $this->matcher->preferenceKey($record->accepted_preference_text))
            ->map(fn (Collection $group, string $key): array => $this->groupPayload($key, $group, $states, $catalog))
            ->sortBy(fn (array $group): array => [mb_strtolower((string) ($group['display_preference'] ?? ''), 'UTF-8'), $group['preference_key']])
            ->values()
            ->all();

        return [
            'batch_id' => (int) $batch->batch_id,
            'metrics' => [
                'total_records' => $records->count(),
                'unmatched_records' => $states->filter(fn (string $state): bool => $state === 'unmatched')->count(),
                'matched_records' => $states->filter(fn (string $state): bool => $state === 'matched')->count(),
                'locked_records' => $states->filter(fn (string $state): bool => $state === 'locked')->count(),
                'missing_preference_records' => $records->filter(fn (MinistryPlacementRecord $record): bool => $this->matcher->normalize($record->accepted_preference_text) === '')->count(),
                'stale_match_records' => $states->filter(fn (string $state): bool => $state === 'stale_match')->count(),
            ],
            'groups' => $groups,
        ];
    }

    public function match(int $recordId, int $programId, User $actor): MinistryPlacementRecord
    {
        return DB::transaction(function () use ($recordId, $programId, $actor): MinistryPlacementRecord {
            $record = MinistryPlacementRecord::query()->lockForUpdate()->findOrFail($recordId);
            $this->assertMutable($record);
            $program = $this->activeProgram($programId);

            if ($record->processing_status === 'program_matched'
                && (int) $record->matched_academic_program_id === (int) $program->academic_program_id) {
                return $this->freshRecord($record);
            }

            $previousProgramId = $record->matched_academic_program_id === null ? null : (int) $record->matched_academic_program_id;
            $record->forceFill([
                'matched_academic_program_id' => (int) $program->academic_program_id,
                'processing_status' => 'program_matched',
            ])->save();

            $this->audit($actor, 'ministry_placement.program_match', [
                'record_id' => (int) $record->placement_record_id,
                'batch_id' => (int) $record->batch_id,
                'previous_program_id' => $previousProgramId,
                'new_program_id' => (int) $program->academic_program_id,
            ]);

            return $this->freshRecord($record);
        });
    }

    public function unmatch(int $recordId, User $actor): MinistryPlacementRecord
    {
        return DB::transaction(function () use ($recordId, $actor): MinistryPlacementRecord {
            $record = MinistryPlacementRecord::query()->lockForUpdate()->findOrFail($recordId);
            $this->assertMutable($record);

            if ($record->processing_status === 'imported' && $record->matched_academic_program_id === null) {
                return $this->freshRecord($record);
            }

            $previousProgramId = $record->matched_academic_program_id === null ? null : (int) $record->matched_academic_program_id;
            $record->forceFill([
                'matched_academic_program_id' => null,
                'processing_status' => 'imported',
            ])->save();

            $this->audit($actor, 'ministry_placement.program_unmatch', [
                'record_id' => (int) $record->placement_record_id,
                'batch_id' => (int) $record->batch_id,
                'previous_program_id' => $previousProgramId,
                'new_program_id' => null,
            ]);

            return $this->freshRecord($record);
        });
    }

    /** @return array<string, mixed> */
    public function applyGroup(int $batchId, string $preferenceKey, int $programId, int $expectedEligibleCount, User $actor): array
    {
        return DB::transaction(function () use ($batchId, $preferenceKey, $programId, $expectedEligibleCount, $actor): array {
            MinistryPlacementBatch::query()->lockForUpdate()->findOrFail($batchId);
            $records = MinistryPlacementRecord::query()
                ->where('batch_id', $batchId)
                ->orderBy('placement_record_id')
                ->lockForUpdate()
                ->get()
                ->filter(fn (MinistryPlacementRecord $record): bool => $this->matcher->preferenceKey($record->accepted_preference_text) === $preferenceKey)
                ->values();
            $records->load('matchedAcademicProgram.department.college');

            $normalizedPreferences = $records
                ->map(fn (MinistryPlacementRecord $record): string => $this->matcher->normalize($record->accepted_preference_text))
                ->unique()
                ->values();
            if ($normalizedPreferences->count() !== 1) {
                throw MinistryPlacementException::groupStale();
            }
            $normalizedPreference = (string) $normalizedPreferences->first();
            if ($normalizedPreference === '') {
                throw MinistryPlacementException::groupNotBulkMatchable();
            }

            $states = $records->groupBy(fn (MinistryPlacementRecord $record): string => $record->programMatchState());
            /** @var Collection<int, MinistryPlacementRecord> $eligible */
            $eligible = $states->get('unmatched', collect());
            if ($eligible->count() !== $expectedEligibleCount) {
                throw MinistryPlacementException::groupStale();
            }

            $program = $this->activeProgram($programId);
            $eligibleIds = $eligible->pluck('placement_record_id')->map(fn ($id): int => (int) $id)->all();
            $updatedCount = 0;
            if ($eligibleIds !== []) {
                $updatedCount = MinistryPlacementRecord::query()->whereIn('placement_record_id', $eligibleIds)->update([
                    'matched_academic_program_id' => (int) $program->academic_program_id,
                    'processing_status' => 'program_matched',
                    'updated_at' => now(),
                ]);
                $this->audit($actor, 'ministry_placement.program_match_bulk', [
                    'batch_id' => $batchId,
                    'new_program_id' => (int) $program->academic_program_id,
                    'updated_count' => $updatedCount,
                    'unchanged_count' => 0,
                    'locked_count' => $states->get('locked', collect())->count(),
                ]);
            }

            return [
                'updated_count' => $updatedCount,
                'unchanged_count' => 0,
                'already_matched_count' => $states->get('matched', collect())->count(),
                'stale_match_count' => $states->get('stale_match', collect())->count(),
                'locked_count' => $states->get('locked', collect())->count(),
                'program' => $this->programPayload($program),
            ];
        });
    }

    /** @param Collection<int, MinistryPlacementRecord> $records @param Collection<int, string> $states @param array<int, array<string, mixed>> $catalog */
    private function groupPayload(string $key, Collection $records, Collection $states, array $catalog): array
    {
        $groupStates = $records->map(fn (MinistryPlacementRecord $record): string => $states->get((int) $record->placement_record_id));
        $displayPreference = $records->map(fn (MinistryPlacementRecord $record): ?string => $record->accepted_preference_text)
            ->first(fn (?string $value): bool => $this->matcher->normalize($value) !== '');
        $normalizedPreferences = $records
            ->map(fn (MinistryPlacementRecord $record): string => $this->matcher->normalize($record->accepted_preference_text))
            ->unique()
            ->values();
        $bulkMatchable = $normalizedPreferences->count() === 1 && (string) $normalizedPreferences->first() !== '';
        $individualReviewCount = $groupStates->filter(fn (string $state): bool => $state === 'unmatched')->count();
        $currentPrograms = $records->filter(fn (MinistryPlacementRecord $record): bool => $record->matched_academic_program_id !== null)
            ->groupBy('matched_academic_program_id')
            ->map(function (Collection $matched): array {
                /** @var MinistryPlacementRecord $record */
                $record = $matched->first();
                $program = $record->matchedAcademicProgram;

                return [
                    'academic_program_id' => (int) $record->matched_academic_program_id,
                    'program_code' => $program?->program_code,
                    'program_name' => $program?->program_name,
                    'record_count' => $matched->count(),
                    'program_match_state' => $record->programMatchState(),
                ];
            })->sortBy('academic_program_id')->values()->all();

        return [
            'preference_key' => $key,
            'display_preference' => $displayPreference,
            'record_count' => $records->count(),
            'bulk_matchable' => $bulkMatchable,
            'bulk_eligible_unmatched_count' => $bulkMatchable ? $individualReviewCount : 0,
            'individual_review_count' => $individualReviewCount,
            'already_matched_count' => $groupStates->filter(fn (string $state): bool => $state === 'matched')->count(),
            'stale_match_count' => $groupStates->filter(fn (string $state): bool => $state === 'stale_match')->count(),
            'locked_count' => $groupStates->filter(fn (string $state): bool => $state === 'locked')->count(),
            'current_programs' => $currentPrograms,
        ] + $this->matcher->suggestions($displayPreference, $catalog);
    }

    private function activeProgramsQuery(): Builder
    {
        return AcademicProgram::query()
            ->select(['academic_program_id', 'department_id', 'program_code', 'program_name', 'degree_level', 'is_active'])
            ->where('is_active', true)
            ->whereHas('department', fn (Builder $department) => $department
                ->where('is_active', true)
                ->whereHas('college', fn (Builder $college) => $college->where('is_active', true)))
            ->with([
                'department' => fn ($department) => $department->select(['department_id', 'college_id', 'department_code', 'department_name', 'is_active']),
                'department.college' => fn ($college) => $college->select(['college_id', 'college_code', 'college_name', 'is_active']),
            ]);
    }

    private function activeProgram(int $programId): AcademicProgram
    {
        $program = $this->activeProgramsQuery()->whereKey($programId)->first();
        if ($program === null) {
            throw MinistryPlacementException::programUnavailable();
        }

        return $program;
    }

    private function assertMutable(MinistryPlacementRecord $record): void
    {
        if ($record->applicant_id !== null || ! in_array($record->processing_status, ['imported', 'program_matched'], true)) {
            throw MinistryPlacementException::recordLocked();
        }
    }

    private function freshRecord(MinistryPlacementRecord $record): MinistryPlacementRecord
    {
        return $record->fresh()->load('matchedAcademicProgram.department.college');
    }

    /** @return array<string, mixed> */
    private function programPayload(AcademicProgram $program): array
    {
        $department = $program->department;
        $college = $department?->college;

        return [
            'academic_program_id' => (int) $program->academic_program_id,
            'program_code' => $program->program_code,
            'program_name' => $program->program_name,
            'degree_level' => $program->degree_level,
            'department_id' => $department === null ? null : (int) $department->department_id,
            'department_code' => $department?->department_code,
            'department_name' => $department?->department_name,
            'college_id' => $college === null ? null : (int) $college->college_id,
            'college_code' => $college?->college_code,
            'college_name' => $college?->college_name,
        ];
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
