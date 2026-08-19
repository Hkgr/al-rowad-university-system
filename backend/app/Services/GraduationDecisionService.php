<?php

namespace App\Services;

use App\Exceptions\AcademicRecordException;
use App\Exceptions\AcademicRequirementConfigurationException;
use App\Exceptions\GraduationEligibilityException;
use App\Models\Student;
use App\Models\StudentGraduationDecision;
use App\Models\StudentGraduationEvent;
use App\Models\StudentStatus;
use App\Models\User;
use App\Support\AcademicRecordWorkflow;
use App\Support\AcademicQueuePagination;
use App\Support\GraduationGpaPolicy;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class GraduationDecisionService
{
    public function __construct(
        private GradeService $grades,
        private GraduationEligibilityService $graduation,
        private GraduationGpaPolicy $gpaPolicy,
        private AcademicRecordGraphLocker $locks,
        private DataScopeService $dataScopes,
    ) {
    }

    public function index(User $user, ?string $status = null, ?int $studentId = null, ?int $perPage = null): array
    {
        $this->assertCanView($user);
        $this->assertSchemaReady();

        $query = StudentGraduationDecision::query()
            ->whereHas('student', fn ($students) => $this->dataScopes->scopeStudents($students, $user))
            ->with($this->displayRelations())
            ->orderByDesc('submitted_at')
            ->orderByDesc('student_graduation_decision_id');

        if ($status !== null) {
            $query->where('status', $status);
        }
        if ($studentId !== null) {
            $query->where('student_id', $studentId);
        }

        $paginator = $query->paginate(AcademicQueuePagination::perPage($perPage));

        return [
            'decisions' => $paginator->getCollection()
                ->map(fn (StudentGraduationDecision $decision): array => $this->present($decision))
                ->values()
                ->all(),
            'meta' => AcademicQueuePagination::meta($paginator),
        ];
    }

    public function show(User $user, StudentGraduationDecision $decision): array
    {
        $this->assertCanView($user);
        $this->assertSchemaReady();
        $this->assertCanAccessStudent($user, $decision->student()->firstOrFail());

        return $this->present($this->fresh($decision));
    }

    public function submit(User $user, Student $student): array
    {
        $this->assertCanReview($user);
        $this->assertCanAccessStudent($user, $student);
        $this->assertSchemaReady();

        return DB::transaction(function () use ($user, $student): array {
            [$locked] = $this->locks->lockStudentAcademicGraph((int) $student->student_id);
            $locked->loadMissing(['currentAcademicLevel', 'academicProgram', 'studentStatus']);
            $current = $this->locks->lockCurrentGraduation((int) $locked->student_id);
            if ($current !== null) {
                throw AcademicRecordException::graduationNotEligible();
            }
            if ($locked->studentStatus?->status_code === AcademicRecordWorkflow::GRADUATED_STATUS) {
                throw AcademicRecordException::graduationAlreadyMaterialized();
            }

            $snapshot = $this->buildSnapshot($locked);
            $this->assertReadyForSubmission($snapshot);

            $now = now();
            $decision = StudentGraduationDecision::query()->create([
                'student_id' => $locked->student_id,
                'academic_program_id' => $locked->academic_program_id,
                'current_academic_level_id' => $locked->current_academic_level_id,
                'status' => AcademicRecordWorkflow::STATUS_SUBMITTED,
                'decision_result' => null,
                'current_slot' => AcademicRecordWorkflow::CURRENT_SLOT,
                'cumulative_gpa_snapshot' => $snapshot['cumulative_gpa'],
                'earned_hours_snapshot' => $snapshot['earned_hours'],
                'required_hours_snapshot' => $snapshot['required_hours'],
                'eligibility_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                'submitted_by_user_id' => $user->user_id,
                'submitted_at' => $now,
            ]);

            $this->writeEvent(
                $decision,
                AcademicRecordWorkflow::EVENT_GRADUATION_SUBMITTED,
                $user,
                null,
                AcademicRecordWorkflow::STATUS_SUBMITTED,
                null
            );

            return $this->present($this->fresh($decision));
        });
    }

    public function returnForModification(User $user, StudentGraduationDecision $decision, string $notes): array
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

    public function approve(User $user, StudentGraduationDecision $decision): array
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

        $current = StudentGraduationDecision::query()
            ->where('student_id', $student->student_id)
            ->where('current_slot', AcademicRecordWorkflow::CURRENT_SLOT)
            ->lockForUpdate()
            ->get();

        foreach ($current as $decision) {
            $this->supersedeUnlocked($user, $decision, $notes);
        }
    }

    /**
     * HTTP conflicts for stale graduation must be raised AFTER the supersede
     * transaction commits. Throwing inside DB::transaction() would roll the
     * persisted stale/superseded state back.
     *
     * @param  array{decision: array, outcome: ?string}  $result
     */
    private function finishDecision(array $result): array
    {
        return match ($result['outcome'] ?? null) {
            AcademicRecordException::GRADUATION_DECISION_STALE => throw AcademicRecordException::graduationDecisionStale(),
            AcademicRecordException::GRADUATION_ALREADY_MATERIALIZED => throw AcademicRecordException::graduationAlreadyMaterialized(),
            AcademicRecordException::GRADUATION_RESULTS_NOT_FINAL => throw AcademicRecordException::graduationResultsNotFinal(),
            AcademicRecordException::GRADUATION_NOT_ELIGIBLE => throw AcademicRecordException::graduationNotEligible(),
            AcademicRecordException::GRADUATION_GPA_REQUIREMENT_NOT_MET => throw AcademicRecordException::graduationGpaRequirementNotMet(),
            AcademicRecordException::GRADUATION_STATUS_NOT_CONFIGURED => throw AcademicRecordException::graduationStatusNotConfigured(),
            default => $result['decision'],
        };
    }

    /**
     * @return array{decision: array, outcome: ?string}
     */
    private function decide(User $user, StudentGraduationDecision $decision, string $target, ?string $reason): array
    {
        return DB::transaction(function () use ($user, $decision, $target, $reason): array {
            [$student] = $this->locks->lockStudentAcademicGraph((int) $decision->student_id);
            $student->loadMissing(['currentAcademicLevel', 'academicProgram', 'studentStatus']);
            $locked = $this->locks->lockGraduationById((int) $decision->student_graduation_decision_id);
            if ($locked === null) {
                return $this->decisionConflict($decision, AcademicRecordException::GRADUATION_DECISION_STALE);
            }

            if ($locked->isApproved() || $locked->isMaterialized()) {
                if ($target === AcademicRecordWorkflow::STATUS_APPROVED && $locked->isMaterialized()) {
                    return $this->decisionOk($locked);
                }

                return $this->decisionConflict($locked, AcademicRecordException::GRADUATION_ALREADY_MATERIALIZED);
            }

            if (! $locked->isCurrent() || $locked->status === AcademicRecordWorkflow::STATUS_SUPERSEDED) {
                return $this->decisionConflict($locked, AcademicRecordException::GRADUATION_DECISION_STALE);
            }

            if (! $locked->isSubmitted()) {
                throw AcademicRecordException::graduationNotEligible();
            }

            $snapshot = $this->buildSnapshot($student);
            $stale = $this->staleReason($student, $locked, $snapshot);
            if ($stale !== null) {
                $this->supersedeUnlocked($user, $locked, $stale);

                return $this->decisionConflict($locked, AcademicRecordException::GRADUATION_DECISION_STALE);
            }

            if ($snapshot['unfinalized_academic_work'] !== []) {
                return $this->decisionConflict($locked, AcademicRecordException::GRADUATION_RESULTS_NOT_FINAL);
            }

            if ($target === AcademicRecordWorkflow::STATUS_APPROVED) {
                try {
                    $this->graduation->assertEligible($student);
                } catch (GraduationEligibilityException|AcademicRequirementConfigurationException) {
                    $this->supersedeUnlocked($user, $locked, 'no_longer_eligible');

                    return $this->decisionConflict($locked, AcademicRecordException::GRADUATION_DECISION_STALE);
                }
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
                    AcademicRecordWorkflow::EVENT_GRADUATION_RETURNED,
                    $user,
                    $from,
                    AcademicRecordWorkflow::STATUS_RETURNED,
                    $reason
                );

                return $this->decisionOk($locked);
            }

            if ($snapshot['eligible'] !== true) {
                $this->supersedeUnlocked($user, $locked, 'no_longer_eligible');

                return $this->decisionConflict($locked, AcademicRecordException::GRADUATION_DECISION_STALE);
            }
            if (! $this->gpaPolicy->satisfies($snapshot['cumulative_gpa'])) {
                return $this->decisionConflict($locked, AcademicRecordException::GRADUATION_GPA_REQUIREMENT_NOT_MET);
            }

            $graduatedStatusId = StudentStatus::query()
                ->where('status_code', AcademicRecordWorkflow::GRADUATED_STATUS)
                ->where('is_active', true)
                ->value('student_status_id');
            if ($graduatedStatusId === null) {
                return $this->decisionConflict($locked, AcademicRecordException::GRADUATION_STATUS_NOT_CONFIGURED);
            }

            $from = $locked->status;
            $now = now();
            $student->update([
                'student_status_id' => $graduatedStatusId,
            ]);
            $locked->update([
                'status' => AcademicRecordWorkflow::STATUS_APPROVED,
                'decision_result' => AcademicRecordWorkflow::RESULT_GRADUATED,
                'current_slot' => null,
                'reviewed_by_user_id' => $user->user_id,
                'reviewed_at' => $now,
                'approved_at' => $now,
                'materialized_at' => $now,
            ]);
            $this->writeEvent(
                $locked,
                AcademicRecordWorkflow::EVENT_GRADUATION_APPROVED,
                $user,
                $from,
                AcademicRecordWorkflow::STATUS_APPROVED,
                null
            );
            $this->writeEvent(
                $locked,
                AcademicRecordWorkflow::EVENT_GRADUATION_MATERIALIZED,
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
    private function decisionOk(StudentGraduationDecision $decision): array
    {
        return [
            'decision' => $this->present($this->fresh($decision)),
            'outcome' => null,
        ];
    }

    /**
     * @return array{decision: array, outcome: string}
     */
    private function decisionConflict(StudentGraduationDecision $decision, string $outcome): array
    {
        return [
            'decision' => $this->present($this->fresh($decision)),
            'outcome' => $outcome,
        ];
    }

    private function supersedeUnlocked(User $user, StudentGraduationDecision $decision, string $notes): void
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
            AcademicRecordWorkflow::EVENT_GRADUATION_STALE,
            $user,
            $from,
            AcademicRecordWorkflow::STATUS_SUPERSEDED,
            $notes
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function staleReason(Student $student, StudentGraduationDecision $decision, array $snapshot): ?string
    {
        if ($student->studentStatus?->status_code === AcademicRecordWorkflow::GRADUATED_STATUS
            && ! $decision->isMaterialized()) {
            return 'student_already_graduated';
        }
        if ((int) $student->academic_program_id !== (int) $decision->academic_program_id) {
            return 'academic_program_changed';
        }
        if ((int) $student->current_academic_level_id !== (int) $decision->current_academic_level_id) {
            return 'current_level_changed';
        }
        if ($this->decimalChanged($decision->cumulative_gpa_snapshot, $snapshot['cumulative_gpa'])) {
            return 'cumulative_gpa_changed';
        }
        if ((int) $decision->earned_hours_snapshot !== (int) $snapshot['earned_hours']
            || (int) $decision->required_hours_snapshot !== (int) $snapshot['required_hours']) {
            return 'eligibility_changed';
        }
        if (($snapshot['eligible'] ?? false) !== true) {
            return 'no_longer_eligible';
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
     * @param  array<string, mixed>  $snapshot
     */
    private function assertReadyForSubmission(array $snapshot): void
    {
        if ($snapshot['unfinalized_academic_work'] !== []) {
            throw AcademicRecordException::graduationResultsNotFinal();
        }
        if ($snapshot['eligible'] !== true) {
            throw AcademicRecordException::graduationNotEligible();
        }
        if (! $this->gpaPolicy->satisfies($snapshot['cumulative_gpa'])) {
            throw AcademicRecordException::graduationGpaRequirementNotMet();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSnapshot(Student $student): array
    {
        try {
            $eligibility = $this->graduation->evaluate($student);
        } catch (GraduationEligibilityException $exception) {
            $eligibility = $exception->errors['graduation_eligibility'] ?? [
                'eligible' => false,
                'blockers' => [['code' => $exception->errorCode]],
            ];
        } catch (AcademicRequirementConfigurationException $exception) {
            $eligibility = [
                'eligible' => false,
                'total_required_hours' => 0,
                'blockers' => [['code' => $exception->errorCode ?? 'academic_requirement_configuration']],
            ];
        }

        $metrics = $this->grades->officialCumulativeMetrics($student);
        $unfinalized = $this->grades->unfinalizedAcademicWork($student);

        return [
            'student_id' => $student->student_id,
            'academic_program_id' => $student->academic_program_id,
            'current_academic_level_id' => $student->current_academic_level_id,
            'cumulative_gpa' => $metrics['cumulative_gpa'],
            'earned_hours' => $metrics['earned_hours'],
            'required_hours' => (int) ($eligibility['total_required_hours'] ?? 0),
            'eligible' => (bool) ($eligibility['eligible'] ?? false),
            'eligibility' => $eligibility,
            'gpa_policy' => $this->gpaPolicy->describe(),
            'unfinalized_academic_work' => $unfinalized,
            'repeated_courses_handling' => $metrics['repeated_courses_handling'],
        ];
    }

    private function writeEvent(
        StudentGraduationDecision $decision,
        string $eventType,
        User $user,
        ?string $from,
        ?string $to,
        ?string $notes
    ): void {
        StudentGraduationEvent::query()->create([
            'student_graduation_decision_id' => $decision->student_graduation_decision_id,
            'event_type' => $eventType,
            'actor_user_id' => $user->user_id,
            'from_status' => $from,
            'to_status' => $to,
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }

    private function present(StudentGraduationDecision $decision): array
    {
        $eligibility = null;
        if (is_string($decision->eligibility_snapshot) && $decision->eligibility_snapshot !== '') {
            $decoded = json_decode($decision->eligibility_snapshot, true);
            $eligibility = is_array($decoded) ? $decoded : null;
        }

        return [
            'student_graduation_decision_id' => $decision->student_graduation_decision_id,
            'student_id' => $decision->student_id,
            'academic_program_id' => $decision->academic_program_id,
            'current_academic_level_id' => $decision->current_academic_level_id,
            'status' => $decision->status,
            'decision_result' => $decision->decision_result,
            'current_slot' => $decision->current_slot,
            'cumulative_gpa_snapshot' => $decision->cumulative_gpa_snapshot !== null ? (float) $decision->cumulative_gpa_snapshot : null,
            'earned_hours_snapshot' => (int) $decision->earned_hours_snapshot,
            'required_hours_snapshot' => (int) $decision->required_hours_snapshot,
            'eligibility' => $eligibility,
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

    private function fresh(StudentGraduationDecision $decision): StudentGraduationDecision
    {
        return $decision->fresh($this->displayRelations()) ?? $decision;
    }

    /**
     * @return list<string>
     */
    private function displayRelations(): array
    {
        return ['student', 'academicProgram', 'currentAcademicLevel'];
    }

    private function requireReturnReason(?string $notes): string
    {
        $trimmed = trim((string) $notes);
        if (mb_strlen($trimmed) < AcademicRecordWorkflow::RETURN_NOTES_MIN) {
            throw AcademicRecordException::graduationNotEligible();
        }

        return mb_substr($trimmed, 0, AcademicRecordWorkflow::RETURN_NOTES_MAX);
    }

    private function assertSchemaReady(): void
    {
        if (! AcademicRecordWorkflow::schemaReady()) {
            throw AcademicRecordException::graduationStatusNotConfigured();
        }
    }

    private function assertCanView(User $user): void
    {
        if (! $user->hasPermission(AcademicRecordWorkflow::PERMISSION_GRADUATION_VIEW)) {
            throw new AccessDeniedHttpException('Graduation decision view permission is required.');
        }
    }

    private function assertCanReview(User $user): void
    {
        if (! $user->isRegistrationOfficer()
            || ! $user->effectivePermissions()->contains(AcademicRecordWorkflow::PERMISSION_GRADUATION_REVIEW)) {
            throw AcademicRecordException::graduationReviewForbidden();
        }
    }

    private function assertCanAccessStudent(User $user, Student $student): void
    {
        if (! $this->dataScopes->canAccessStudent($user, $student)) {
            throw new AccessDeniedHttpException('You are not authorized to access this student.');
        }
    }
}
