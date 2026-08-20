<?php

namespace App\Services;

use App\Exceptions\SupplementaryExamPeriodGovernanceException;
use App\Models\AcademicYear;
use App\Models\Semester;
use App\Models\SupplementaryExamPeriod;
use App\Models\SupplementaryExamPeriodEvent;
use App\Models\User;
use App\Support\SupplementaryExamPeriodGovernance;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SupplementaryExamPeriodGovernanceService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function catalog(User $user, array $filters = []): array
    {
        $this->assertCanView($user);

        return [
            'academic_years' => AcademicYear::query()
                ->orderByDesc('is_current')
                ->orderByDesc('start_date')
                ->get(['academic_year_id', 'year_name', 'start_date', 'end_date', 'is_current', 'is_active'])
                ->map(fn (AcademicYear $year) => [
                    'academic_year_id' => $year->academic_year_id,
                    'id' => $year->academic_year_id,
                    'name' => $year->year_name,
                    'year_name' => $year->year_name,
                    'is_current' => (bool) $year->is_current,
                    'is_active' => (bool) $year->is_active,
                ])
                ->values()
                ->all(),
            'semesters' => Semester::query()
                ->orderBy('semester_order')
                ->get(['semester_id', 'semester_code', 'semester_name', 'semester_order', 'is_active'])
                ->map(fn (Semester $semester) => [
                    'semester_id' => $semester->semester_id,
                    'id' => $semester->semester_id,
                    'name' => $semester->semester_name,
                    'semester_name' => $semester->semester_name,
                    'semester_code' => $semester->semester_code,
                    'semester_order' => $semester->semester_order,
                    'is_active' => (bool) $semester->is_active,
                ])
                ->values()
                ->all(),
            'periods' => $this->periodQuery($filters)
                ->with($this->periodRelations())
                ->orderBy('academic_year_id')
                ->orderBy('semester_id')
                ->get(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listPeriods(User $user, array $filters = [])
    {
        $this->assertCanView($user);

        return $this->periodQuery($filters)
            ->with($this->periodRelations())
            ->orderBy('academic_year_id')
            ->orderBy('semester_id')
            ->get();
    }

    public function findPeriod(User $user, SupplementaryExamPeriod $period): SupplementaryExamPeriod
    {
        $this->assertCanView($user);
        $period->loadMissing($this->periodRelations());

        return $period;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function announce(User $user, array $payload): SupplementaryExamPeriod
    {
        $this->assertCanDecide($user);
        $this->assertSchemaReady();

        if (! DB::transactionLevel()) {
            return DB::transaction(fn () => $this->announceInsideTransaction($user, $payload));
        }

        return $this->announceInsideTransaction($user, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function announceInsideTransaction(User $user, array $payload): SupplementaryExamPeriod
    {
        if (! DB::transactionLevel()) {
            throw SupplementaryExamPeriodGovernanceException::transactionRequired();
        }

        $this->assertCanDecide($user);

        $yearId = (int) $payload['academic_year_id'];
        $semesterId = (int) $payload['semester_id'];

        AcademicYear::query()->whereKey($yearId)->lockForUpdate()->firstOrFail();
        Semester::query()->whereKey($semesterId)->lockForUpdate()->firstOrFail();

        $existing = SupplementaryExamPeriod::query()
            ->where('academic_year_id', $yearId)
            ->where('semester_id', $semesterId)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            throw SupplementaryExamPeriodGovernanceException::identityExists();
        }

        $period = new SupplementaryExamPeriod;
        $period->academic_year_id = $yearId;
        $period->semester_id = $semesterId;
        $period->period_name = (string) $payload['period_name'];
        $period->start_date = $payload['start_date'];
        $period->end_date = $payload['end_date'];
        $period->decision_note = $payload['decision_note'] ?? null;
        $period->status = SupplementaryExamPeriodGovernance::STATUS_ANNOUNCED;
        $period->is_active = true;
        $period->opened_by_user_id = $user->user_id;
        $period->opened_at = now();

        try {
            $period->save();
        } catch (QueryException $exception) {
            if ($this->isUniqueIdentityViolation($exception)) {
                throw SupplementaryExamPeriodGovernanceException::identityExists();
            }

            throw $exception;
        }

        SupplementaryExamPeriodEvent::query()->create([
            'supplementary_exam_period_id' => $period->supplementary_exam_period_id,
            'event_type' => SupplementaryExamPeriodGovernance::EVENT_ANNOUNCED,
            'from_status' => null,
            'to_status' => SupplementaryExamPeriodGovernance::STATUS_ANNOUNCED,
            'actor_user_id' => $user->user_id,
            'notes' => $payload['decision_note'] ?? null,
            'created_at' => now(),
        ]);

        return $period->fresh($this->periodRelations());
    }

    /**
     * Assigned role_permissions only. Super Admin virtual grants from
     * User::hasPermission() must not impersonate academic authorities.
     */
    private function holdsAssignedPermission(User $user, string $permission): bool
    {
        return $user->effectivePermissions()->contains($permission);
    }

    private function assertCanDecide(User $user): void
    {
        if (! $user->isScientificVicePresident()
            || ! $this->holdsAssignedPermission($user, SupplementaryExamPeriodGovernance::PERMISSION_DECIDE)) {
            throw SupplementaryExamPeriodGovernanceException::decisionForbidden();
        }
    }

    private function assertCanView(User $user): void
    {
        if (! $user->hasPermission(SupplementaryExamPeriodGovernance::PERMISSION_VIEW)) {
            throw SupplementaryExamPeriodGovernanceException::viewForbidden();
        }
    }

    private function assertSchemaReady(): void
    {
        if (! SupplementaryExamPeriodGovernance::schemaReady()) {
            throw SupplementaryExamPeriodGovernanceException::schemaNotReady();
        }
    }

    /**
     * @return list<string>
     */
    private function periodRelations(): array
    {
        $relations = ['academicYear', 'semester'];
        if (Schema::hasColumn('supplementary_exam_periods', 'opened_by_user_id')) {
            $relations[] = 'openedBy';
        }

        return $relations;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function periodQuery(array $filters)
    {
        $query = SupplementaryExamPeriod::query();

        if (isset($filters['academic_year_id'])) {
            $query->where('academic_year_id', (int) $filters['academic_year_id']);
        }
        if (isset($filters['semester_id'])) {
            $query->where('semester_id', (int) $filters['semester_id']);
        }
        if (isset($filters['status']) && Schema::hasColumn('supplementary_exam_periods', 'status')) {
            $query->where('status', (string) $filters['status']);
        }

        return $query;
    }

    private function isUniqueIdentityViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        if ($sqlState !== '23000') {
            return false;
        }

        $message = $exception->getMessage();

        return str_contains($message, 'uq_sep_year_semester')
            || str_contains($message, 'academic_year_id')
            || str_contains($message, 'Duplicate');
    }
}
