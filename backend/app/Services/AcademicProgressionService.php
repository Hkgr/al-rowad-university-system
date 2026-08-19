<?php

namespace App\Services;

use App\Exceptions\AcademicRecordException;
use App\Models\AcademicLevel;
use App\Models\AcademicYear;
use App\Models\ProgramCourse;
use App\Models\Student;
use App\Models\StudentProgressionDecision;
use App\Models\StudentProgressionEvent;
use App\Models\User;
use App\Support\AcademicRecordWorkflow;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AcademicProgressionService
{
    public function __construct(
        private GradeService $grades,
        private GraduationEligibilityService $graduation,
        private AcademicRecordGraphLocker $locks,
        private DataScopeService $dataScopes,
    ) {
    }

    public function evaluate(User $user, Student $student, ?int $academicYearId = null): array
    {
        $this->assertCanView($user);
        $this->assertCanAccessStudent($user, $student);
        $this->assertSchemaReady();
        $student->loadMissing(['currentAcademicLevel', 'academicProgram', 'studentStatus']);

        return $this->buildEvidence($student, $academicYearId);
    }

    public function index(User $user, ?string $status = null, ?int $studentId = null): array
    {
        $this->assertCanView($user);
        $this->assertSchemaReady();

        $query = StudentProgressionDecision::query()
            ->whereHas('student', fn ($students) => $this->dataScopes->scopeStudents($students, $user))
            ->with($this->displayRelations())
            ->orderByDesc('submitted_at')
            ->orderByDesc('student_progression_decision_id');

        if ($status !== null) {
            $query->where('status', $status);
        }
        if ($studentId !== null) {
            $query->where('student_id', $studentId);
        }

        return [
            'decisions' => $query->get()
                ->map(fn (StudentProgressionDecision $decision): array => $this->present($decision))
                ->values()
                ->all(),
        ];
    }

    public function show(User $user, StudentProgressionDecision $decision): array
    {
        $this->assertCanView($user);
        $this->assertSchemaReady();
        $this->assertCanAccessStudent($user, $decision->student()->firstOrFail());

        return $this->present($this->fresh($decision));
    }

    public function submit(User $user, Student $student, int $academicYearId, string $decisionResult): array
    {
        $this->assertCanReview($user);
        $this->assertCanAccessStudent($user, $student);
        $this->assertSchemaReady();
        AcademicYear::query()->findOrFail($academicYearId);
        if (! in_array($decisionResult, AcademicRecordWorkflow::progressionResults(), true)) {
            throw AcademicRecordException::academicProgressionNotReady();
        }

        return DB::transaction(function () use ($user, $student, $academicYearId, $decisionResult): array {
            [$locked] = $this->locks->lockStudentAcademicGraph((int) $student->student_id);
            $locked->loadMissing(['currentAcademicLevel', 'academicProgram', 'studentStatus']);
            $current = $this->locks->lockCurrentProgression((int) $locked->student_id, $academicYearId);
            if ($current !== null) {
                throw AcademicRecordException::academicProgressionNotReady();
            }

            $evidence = $this->buildEvidence($locked, $academicYearId);
            $this->assertSubmittable($evidence, $decisionResult);

            $now = now();
            $decision = StudentProgressionDecision::query()->create([
                'student_id' => $locked->student_id,
                'academic_program_id' => $locked->academic_program_id,
                'academic_year_id' => $academicYearId,
                'from_academic_level_id' => $evidence['current_academic_level_id'],
                'to_academic_level_id' => $decisionResult === AcademicRecordWorkflow::RESULT_PROMOTED
                    ? $evidence['candidate_next_academic_level_id']
                    : null,
                'status' => AcademicRecordWorkflow::STATUS_SUBMITTED,
                'decision_result' => $decisionResult,
                'current_slot' => AcademicRecordWorkflow::CURRENT_SLOT,
                'term_gpa_snapshot' => $evidence['term_gpa'],
                'cumulative_gpa_snapshot' => $evidence['cumulative_gpa'],
                'earned_hours_snapshot' => $evidence['earned_hours'],
                'attempted_hours_snapshot' => $evidence['attempted_hours'],
                'failed_courses_count_snapshot' => $evidence['failed_courses_count'],
                'evidence_snapshot' => json_encode($evidence, JSON_UNESCAPED_UNICODE),
                'submitted_by_user_id' => $user->user_id,
                'submitted_at' => $now,
            ]);

            $this->writeEvent(
                $decision,
                AcademicRecordWorkflow::EVENT_PROGRESSION_SUBMITTED,
                $user,
                null,
                AcademicRecordWorkflow::STATUS_SUBMITTED,
                null
            );

            return $this->present($this->fresh($decision));
        });
    }

    public function returnForModification(User $user, StudentProgressionDecision $decision, string $notes): array
    {
        $this->assertCanReview($user);
        $this->assertSchemaReady();
        $this->assertCanAccessStudent($user, $decision->student()->firstOrFail());
        $trimmed = $this->requireReturnReason($notes);

        return $this->finishDecision($this->decide(
            $user,
            $decision,
            AcademicRecordWorkflow::STATUS_RETURNED,
            $trimmed
        ));
    }

    public function approve(User $user, StudentProgressionDecision $decision): array
    {
        $this->assertCanReview($user);
        $this->assertSchemaReady();
        $this->assertCanAccessStudent($user, $decision->student()->firstOrFail());

        return $this->finishDecision($this->decide(
            $user,
            $decision,
            AcademicRecordWorkflow::STATUS_APPROVED,
            null
        ));
    }

    public function supersedeCurrentForStudent(User $user, Student $student, string $notes): void
    {
        if (! AcademicRecordWorkflow::schemaReady()) {
            return;
        }

        $current = StudentProgressionDecision::query()
            ->where('student_id', $student->student_id)
            ->where('current_slot', AcademicRecordWorkflow::CURRENT_SLOT)
            ->lockForUpdate()
            ->get();

        foreach ($current as $decision) {
            $this->supersedeUnlocked($user, $decision, $notes);
        }
    }

    /**
     * HTTP conflicts for stale progression must be raised AFTER the supersede
     * transaction commits. Throwing inside DB::transaction() would roll the
     * persisted stale/superseded state back.
     *
     * @param  array{decision: array, outcome: ?string}  $result
     */
    private function finishDecision(array $result): array
    {
        return match ($result['outcome'] ?? null) {
            AcademicRecordException::ACADEMIC_PROGRESSION_STALE => throw AcademicRecordException::academicProgressionStale(),
            AcademicRecordException::ACADEMIC_PROGRESSION_ALREADY_MATERIALIZED => throw AcademicRecordException::academicProgressionAlreadyMaterialized(),
            AcademicRecordException::ACADEMIC_RESULTS_NOT_FINAL => throw AcademicRecordException::academicResultsNotFinal(),
            AcademicRecordException::ACADEMIC_PROGRESSION_NOT_READY => throw AcademicRecordException::academicProgressionNotReady(),
            default => $result['decision'],
        };
    }

    /**
     * @return array{decision: array, outcome: ?string}
     */
    private function decide(User $user, StudentProgressionDecision $decision, string $target, ?string $reason): array
    {
        return DB::transaction(function () use ($user, $decision, $target, $reason): array {
            [$student] = $this->locks->lockStudentAcademicGraph((int) $decision->student_id);
            $student->loadMissing(['currentAcademicLevel', 'academicProgram', 'studentStatus']);
            $locked = $this->locks->lockProgressionById((int) $decision->student_progression_decision_id);
            if ($locked === null) {
                return $this->decisionConflict($decision, AcademicRecordException::ACADEMIC_PROGRESSION_STALE);
            }

            if ($locked->isApproved() || $locked->isMaterialized()) {
                if ($target === AcademicRecordWorkflow::STATUS_APPROVED && $locked->isMaterialized()) {
                    return $this->decisionOk($locked);
                }

                return $this->decisionConflict($locked, AcademicRecordException::ACADEMIC_PROGRESSION_ALREADY_MATERIALIZED);
            }

            if (! $locked->isCurrent() || $locked->status === AcademicRecordWorkflow::STATUS_SUPERSEDED) {
                return $this->decisionConflict($locked, AcademicRecordException::ACADEMIC_PROGRESSION_STALE);
            }

            if (! $locked->isSubmitted()) {
                throw AcademicRecordException::academicProgressionNotReady();
            }

            $evidence = $this->buildEvidence($student, (int) $locked->academic_year_id);
            $stale = $this->staleReason($student, $locked, $evidence);
            if ($stale !== null) {
                $this->supersedeUnlocked($user, $locked, $stale);

                return $this->decisionConflict($locked, AcademicRecordException::ACADEMIC_PROGRESSION_STALE);
            }

            if ($evidence['unfinalized_academic_work'] !== []) {
                return $this->decisionConflict($locked, AcademicRecordException::ACADEMIC_RESULTS_NOT_FINAL);
            }

            if ($target === AcademicRecordWorkflow::STATUS_RETURNED) {
                $from = $locked->status;
                $now = now();
                $locked->update([
                    'status' => AcademicRecordWorkflow::STATUS_RETURNED,
                    'current_slot' => null,
                    'reviewed_by_user_id' => $user->user_id,
                    'reviewed_at' => $now,
                    'review_notes' => $reason,
                ]);
                $this->writeEvent(
                    $locked,
                    AcademicRecordWorkflow::EVENT_PROGRESSION_RETURNED,
                    $user,
                    $from,
                    AcademicRecordWorkflow::STATUS_RETURNED,
                    $reason
                );

                return $this->decisionOk($locked);
            }

            if ($locked->decision_result === AcademicRecordWorkflow::RESULT_PROMOTED
                && $evidence['candidate_next_academic_level_id'] === null) {
                return $this->decisionConflict($locked, AcademicRecordException::ACADEMIC_PROGRESSION_NOT_READY);
            }

            $from = $locked->status;
            $now = now();
            if ($locked->decision_result === AcademicRecordWorkflow::RESULT_PROMOTED) {
                $student->update([
                    'current_academic_level_id' => $locked->to_academic_level_id,
                ]);
            }

            $locked->update([
                'status' => AcademicRecordWorkflow::STATUS_APPROVED,
                'current_slot' => null,
                'reviewed_by_user_id' => $user->user_id,
                'reviewed_at' => $now,
                'approved_at' => $now,
                'materialized_at' => $now,
            ]);
            $this->writeEvent(
                $locked,
                AcademicRecordWorkflow::EVENT_PROGRESSION_APPROVED,
                $user,
                $from,
                AcademicRecordWorkflow::STATUS_APPROVED,
                null
            );
            $this->writeEvent(
                $locked,
                AcademicRecordWorkflow::EVENT_PROGRESSION_MATERIALIZED,
                $user,
                AcademicRecordWorkflow::STATUS_APPROVED,
                AcademicRecordWorkflow::STATUS_APPROVED,
                null
            );

            return $this->decisionOk($locked);
        });
    }

    /**
     * @return array{decision: array, outcome: null}
     */
    private function decisionOk(StudentProgressionDecision $decision): array
    {
        return [
            'decision' => $this->present($this->fresh($decision)),
            'outcome' => null,
        ];
    }

    /**
     * @return array{decision: array, outcome: string}
     */
    private function decisionConflict(StudentProgressionDecision $decision, string $outcome): array
    {
        return [
            'decision' => $this->present($this->fresh($decision)),
            'outcome' => $outcome,
        ];
    }

    private function supersedeUnlocked(User $user, StudentProgressionDecision $decision, string $notes): void
    {
        $from = $decision->status;
        $now = now();
        $decision->update([
            'status' => AcademicRecordWorkflow::STATUS_SUPERSEDED,
            'current_slot' => null,
            'superseded_at' => $now,
        ]);
        $this->writeEvent(
            $decision,
            AcademicRecordWorkflow::EVENT_PROGRESSION_STALE,
            $user,
            $from,
            AcademicRecordWorkflow::STATUS_SUPERSEDED,
            $notes
        );
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function staleReason(Student $student, StudentProgressionDecision $decision, array $evidence): ?string
    {
        if ($student->studentStatus?->status_code === AcademicRecordWorkflow::GRADUATED_STATUS) {
            return 'student_graduated';
        }
        if ((int) $student->academic_program_id !== (int) $decision->academic_program_id) {
            return 'academic_program_changed';
        }
        if ((int) $student->current_academic_level_id !== (int) $decision->from_academic_level_id) {
            return 'current_level_changed';
        }
        if ($this->decimalChanged($decision->cumulative_gpa_snapshot, $evidence['cumulative_gpa'])) {
            return 'cumulative_gpa_changed';
        }
        if ($this->decimalChanged($decision->term_gpa_snapshot, $evidence['term_gpa'])) {
            return 'term_gpa_changed';
        }
        if ((int) $decision->earned_hours_snapshot !== (int) $evidence['earned_hours']
            || (int) $decision->attempted_hours_snapshot !== (int) $evidence['attempted_hours']
            || (int) $decision->failed_courses_count_snapshot !== (int) $evidence['failed_courses_count']) {
            return 'academic_results_changed';
        }
        if ($decision->decision_result === AcademicRecordWorkflow::RESULT_PROMOTED) {
            if ($evidence['candidate_next_academic_level_id'] === null
                || (int) $decision->to_academic_level_id !== (int) $evidence['candidate_next_academic_level_id']) {
                return 'next_level_changed';
            }
        }

        return null;
    }

    private function decimalChanged(mixed $stored, mixed $current): bool
    {
        if ($stored === null && $current === null) {
            return false;
        }
        if ($stored === null || $current === null) {
            return true;
        }

        return abs((float) $stored - (float) $current) > 0.001;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function assertSubmittable(array $evidence, string $decisionResult): void
    {
        if ($evidence['current_academic_level_id'] === null) {
            throw AcademicRecordException::academicProgressionNotReady();
        }
        if ($evidence['student_status_code'] === AcademicRecordWorkflow::GRADUATED_STATUS) {
            throw AcademicRecordException::academicProgressionNotReady();
        }
        if ($decisionResult === AcademicRecordWorkflow::RESULT_PROMOTED
            && $evidence['candidate_next_academic_level_id'] === null) {
            throw AcademicRecordException::academicProgressionNotReady();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEvidence(Student $student, ?int $academicYearId): array
    {
        $metrics = $this->grades->officialCumulativeMetrics($student);
        $unfinalized = $this->grades->unfinalizedAcademicWork($student);
        $candidate = $this->candidateNextLevel($student);
        $termGpa = null;
        if ($academicYearId !== null) {
            $termGpa = $this->officialTermGpa($student, $academicYearId);
        }

        $eligibility = null;
        try {
            $eligibility = $this->graduation->evaluate($student);
        } catch (\Throwable) {
            $eligibility = null;
        }

        $classification = $unfinalized === []
            ? AcademicRecordWorkflow::CLASSIFICATION_READY_FOR_REVIEW
            : AcademicRecordWorkflow::CLASSIFICATION_BLOCKED_INCOMPLETE_RESULTS;

        return [
            'student_id' => $student->student_id,
            'academic_program_id' => $student->academic_program_id,
            'current_academic_level_id' => $student->current_academic_level_id,
            'current_academic_level' => $student->currentAcademicLevel ? [
                'academic_level_id' => $student->currentAcademicLevel->academic_level_id,
                'level_code' => $student->currentAcademicLevel->level_code,
                'level_name' => $student->currentAcademicLevel->level_name,
                'level_order' => $student->currentAcademicLevel->level_order,
                'is_active' => (bool) $student->currentAcademicLevel->is_active,
            ] : null,
            'candidate_next_academic_level_id' => $candidate?->academic_level_id,
            'candidate_next_academic_level' => $candidate ? [
                'academic_level_id' => $candidate->academic_level_id,
                'level_code' => $candidate->level_code,
                'level_name' => $candidate->level_name,
                'level_order' => $candidate->level_order,
                'is_active' => (bool) $candidate->is_active,
            ] : null,
            'student_status_code' => $student->studentStatus?->status_code,
            'term_gpa' => $termGpa,
            'cumulative_gpa' => $metrics['cumulative_gpa'],
            'earned_hours' => $metrics['earned_hours'],
            'attempted_hours' => $metrics['attempted_hours'],
            'official_completed_courses' => $metrics['official_completed_courses'],
            'failed_courses' => $metrics['failed_courses'],
            'failed_courses_count' => $metrics['failed_courses_count'],
            'unfinalized_academic_work' => $unfinalized,
            'incomplete_unfinalized_academic_work' => $unfinalized,
            'classification' => $classification,
            'graduation_eligibility' => $eligibility,
            'repeated_courses_handling' => $metrics['repeated_courses_handling'],
            'gpa_scale' => $metrics['scale'],
        ];
    }

    /**
     * Latest official semester comes from GradeService chronology, not from
     * whichever StudentAcademicTerm row happens to exist.
     *
     * A finalized snapshot is authoritative only for that exact
     * student/year/semester identity. An earlier finalized term must never
     * override a later semester that has official academic activity.
     */
    private function officialTermGpa(Student $student, int $academicYearId): ?float
    {
        $semesterId = $this->grades->latestOfficialSemesterIdForYear($student, $academicYearId);
        if ($semesterId === null) {
            return null;
        }

        $term = $student->studentAcademicTerms()
            ->where('academic_year_id', $academicYearId)
            ->where('semester_id', $semesterId)
            ->first();

        if ($term !== null && $term->isFinalized()) {
            return $term->term_gpa !== null ? (float) $term->term_gpa : null;
        }

        return $this->grades->officialTermMetrics($student, $academicYearId, $semesterId)['term_gpa'];
    }

    private function candidateNextLevel(Student $student): ?AcademicLevel
    {
        $current = $student->currentAcademicLevel;
        if ($current === null || ! $current->is_active) {
            return null;
        }
        if ($student->academic_program_id === null) {
            return null;
        }

        $programLevelIds = ProgramCourse::query()
            ->where('academic_program_id', $student->academic_program_id)
            ->where('is_active', true)
            ->pluck('academic_level_id')
            ->unique()
            ->filter()
            ->values();

        if ($programLevelIds->isEmpty()) {
            return null;
        }

        return AcademicLevel::query()
            ->where('is_active', true)
            ->whereIn('academic_level_id', $programLevelIds)
            ->where('level_order', '>', (int) $current->level_order)
            ->orderBy('level_order')
            ->orderBy('academic_level_id')
            ->first();
    }

    private function writeEvent(
        StudentProgressionDecision $decision,
        string $eventType,
        User $user,
        ?string $from,
        ?string $to,
        ?string $notes
    ): void {
        StudentProgressionEvent::query()->create([
            'student_progression_decision_id' => $decision->student_progression_decision_id,
            'event_type' => $eventType,
            'actor_user_id' => $user->user_id,
            'from_status' => $from,
            'to_status' => $to,
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }

    private function present(StudentProgressionDecision $decision): array
    {
        $evidence = null;
        if (is_string($decision->evidence_snapshot) && $decision->evidence_snapshot !== '') {
            $decoded = json_decode($decision->evidence_snapshot, true);
            $evidence = is_array($decoded) ? $decoded : null;
        }

        return [
            'student_progression_decision_id' => $decision->student_progression_decision_id,
            'student_id' => $decision->student_id,
            'academic_program_id' => $decision->academic_program_id,
            'academic_year_id' => $decision->academic_year_id,
            'from_academic_level_id' => $decision->from_academic_level_id,
            'to_academic_level_id' => $decision->to_academic_level_id,
            'status' => $decision->status,
            'decision_result' => $decision->decision_result,
            'current_slot' => $decision->current_slot,
            'term_gpa_snapshot' => $decision->term_gpa_snapshot !== null ? (float) $decision->term_gpa_snapshot : null,
            'cumulative_gpa_snapshot' => $decision->cumulative_gpa_snapshot !== null ? (float) $decision->cumulative_gpa_snapshot : null,
            'earned_hours_snapshot' => (int) $decision->earned_hours_snapshot,
            'attempted_hours_snapshot' => (int) $decision->attempted_hours_snapshot,
            'failed_courses_count_snapshot' => (int) $decision->failed_courses_count_snapshot,
            'evidence' => $evidence,
            'submitted_by_user_id' => $decision->submitted_by_user_id,
            'submitted_at' => $decision->submitted_at,
            'reviewed_by_user_id' => $decision->reviewed_by_user_id,
            'reviewed_at' => $decision->reviewed_at,
            'review_notes' => $decision->review_notes,
            'approved_at' => $decision->approved_at,
            'materialized_at' => $decision->materialized_at,
            'superseded_at' => $decision->superseded_at,
        ];
    }

    private function fresh(StudentProgressionDecision $decision): StudentProgressionDecision
    {
        return $decision->fresh($this->displayRelations()) ?? $decision;
    }

    /**
     * @return list<string>
     */
    private function displayRelations(): array
    {
        return [
            'student',
            'academicProgram',
            'academicYear',
            'fromAcademicLevel',
            'toAcademicLevel',
        ];
    }

    private function requireReturnReason(?string $notes): string
    {
        $trimmed = trim((string) $notes);
        if (mb_strlen($trimmed) < AcademicRecordWorkflow::RETURN_NOTES_MIN) {
            throw AcademicRecordException::academicProgressionNotReady();
        }

        return mb_substr($trimmed, 0, AcademicRecordWorkflow::RETURN_NOTES_MAX);
    }

    private function assertSchemaReady(): void
    {
        if (! AcademicRecordWorkflow::schemaReady()) {
            throw AcademicRecordException::academicProgressionNotReady();
        }
    }

    private function assertCanView(User $user): void
    {
        if (! $user->hasPermission(AcademicRecordWorkflow::PERMISSION_PROGRESSION_VIEW)) {
            throw new AccessDeniedHttpException('Academic progression view permission is required.');
        }
    }

    private function assertCanReview(User $user): void
    {
        if (! $user->isRegistrationOfficer()
            || ! $user->effectivePermissions()->contains(AcademicRecordWorkflow::PERMISSION_PROGRESSION_REVIEW)) {
            throw AcademicRecordException::academicProgressionReviewForbidden();
        }
    }

    private function assertCanAccessStudent(User $user, Student $student): void
    {
        if (! $this->dataScopes->canAccessStudent($user, $student)) {
            throw new AccessDeniedHttpException('You are not authorized to access this student.');
        }
    }
}
