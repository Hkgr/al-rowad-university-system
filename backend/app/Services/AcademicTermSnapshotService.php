<?php

namespace App\Services;

use App\Exceptions\AcademicRecordException;
use App\Models\AcademicYear;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentAcademicTerm;
use App\Models\User;
use App\Support\AcademicRecordWorkflow;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AcademicTermSnapshotService
{
    public function __construct(
        private GradeService $grades,
        private AcademicRecordGraphLocker $locks,
        private DataScopeService $dataScopes,
    ) {
    }

    public function index(User $user, Student $student): array
    {
        $this->assertCanView($user);
        $this->assertCanAccessStudent($user, $student);
        $this->assertSchemaReady();

        $terms = StudentAcademicTerm::query()
            ->where('student_id', $student->student_id)
            ->with(['academicYear', 'semester', 'academicLevel', 'finalizedBy'])
            ->orderBy('academic_year_id')
            ->orderBy('semester_id')
            ->get();

        return [
            'student_id' => $student->student_id,
            'terms' => $terms->map(fn (StudentAcademicTerm $term): array => $this->present($term))->values()->all(),
        ];
    }

    public function recalculate(User $user, Student $student, int $academicYearId, int $semesterId): array
    {
        $this->assertCanFinalize($user);
        $this->assertCanAccessStudent($user, $student);
        $this->assertSchemaReady();
        $this->assertTermIdentity($academicYearId, $semesterId);

        return DB::transaction(function () use ($user, $student, $academicYearId, $semesterId): array {
            [$locked] = $this->locks->lockStudentAcademicGraph((int) $student->student_id);
            $term = $this->upsertComputedTerm($locked, $academicYearId, $semesterId, $user, finalize: false);

            return $this->present($term);
        });
    }

    public function finalize(User $user, Student $student, int $academicYearId, int $semesterId): array
    {
        $this->assertCanFinalize($user);
        $this->assertCanAccessStudent($user, $student);
        $this->assertSchemaReady();
        $this->assertTermIdentity($academicYearId, $semesterId);

        return DB::transaction(function () use ($user, $student, $academicYearId, $semesterId): array {
            [$locked] = $this->locks->lockStudentAcademicGraph((int) $student->student_id);
            $existing = StudentAcademicTerm::query()
                ->where('student_id', $locked->student_id)
                ->where('academic_year_id', $academicYearId)
                ->where('semester_id', $semesterId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->isFinalized()) {
                return $this->present($existing->fresh()->load(['academicYear', 'semester', 'academicLevel', 'finalizedBy']));
            }

            if ($this->grades->unfinalizedAcademicWorkForTerm($locked, $academicYearId, $semesterId) !== []) {
                throw AcademicRecordException::academicResultsNotFinal();
            }

            $term = $this->upsertComputedTerm($locked, $academicYearId, $semesterId, $user, finalize: true);

            return $this->present($term);
        });
    }

    public function rejectGenericMutation(?StudentAcademicTerm $term = null): never
    {
        if ($term !== null && $term->isFinalized()) {
            throw AcademicRecordException::academicTermFinalized();
        }

        throw AcademicRecordException::academicTermWorkflowRequired();
    }

    private function upsertComputedTerm(
        Student $student,
        int $academicYearId,
        int $semesterId,
        User $user,
        bool $finalize
    ): StudentAcademicTerm {
        $student->loadMissing('currentAcademicLevel');
        $metrics = $this->grades->officialTermMetrics($student, $academicYearId, $semesterId);
        $levelId = $student->current_academic_level_id;
        if ($levelId === null) {
            throw AcademicRecordException::academicProgressionNotReady();
        }

        $attributes = [
            'academic_level_id' => (int) $levelId,
            'term_gpa' => $metrics['term_gpa'],
            'cumulative_gpa' => $metrics['cumulative_gpa'],
            'total_registered_hours' => $metrics['total_registered_hours'],
            'attempted_hours' => $metrics['attempted_hours'],
            'earned_hours' => $metrics['earned_hours'],
        ];

        $term = StudentAcademicTerm::query()
            ->where('student_id', $student->student_id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester_id', $semesterId)
            ->lockForUpdate()
            ->first();

        if ($term === null) {
            try {
                $term = StudentAcademicTerm::query()->create(array_merge($attributes, [
                    'student_id' => $student->student_id,
                    'academic_year_id' => $academicYearId,
                    'semester_id' => $semesterId,
                    'is_finalized' => false,
                ]));
            } catch (\Illuminate\Database\QueryException $exception) {
                throw AcademicRecordException::academicTermIdentityConflict();
            }
        } elseif ($term->isFinalized()) {
            throw AcademicRecordException::academicTermFinalized();
        } else {
            $term->update($attributes);
        }

        if ($finalize) {
            if ($this->grades->unfinalizedAcademicWorkForTerm($student, $academicYearId, $semesterId) !== []) {
                throw AcademicRecordException::academicResultsNotFinal();
            }

            $now = now();
            $term->update([
                'is_finalized' => true,
                'finalized_at' => $now,
                'finalized_by_user_id' => $user->user_id,
            ]);
        }

        return $term->fresh()->load(['academicYear', 'semester', 'academicLevel', 'finalizedBy']);
    }

    private function present(StudentAcademicTerm $term): array
    {
        return [
            'student_academic_term_id' => $term->student_academic_term_id,
            'student_id' => $term->student_id,
            'academic_year_id' => $term->academic_year_id,
            'semester_id' => $term->semester_id,
            'academic_level_id' => $term->academic_level_id,
            'term_gpa' => $term->term_gpa !== null ? (float) $term->term_gpa : null,
            'cumulative_gpa' => $term->cumulative_gpa !== null ? (float) $term->cumulative_gpa : null,
            'total_registered_hours' => (int) $term->total_registered_hours,
            'attempted_hours' => $term->attempted_hours === null ? null : (int) $term->attempted_hours,
            'earned_hours' => $term->earned_hours === null ? null : (int) $term->earned_hours,
            'is_finalized' => (bool) $term->is_finalized,
            'finalized_at' => $term->finalized_at,
            'finalized_by_user_id' => $term->finalized_by_user_id,
            'academic_year' => $term->academicYear ? [
                'academic_year_id' => $term->academicYear->academic_year_id,
                'year_name' => $term->academicYear->year_name,
            ] : null,
            'semester' => $term->semester ? [
                'semester_id' => $term->semester->semester_id,
                'semester_code' => $term->semester->semester_code,
                'semester_name' => $term->semester->semester_name,
            ] : null,
            'academic_level' => $term->academicLevel ? [
                'academic_level_id' => $term->academicLevel->academic_level_id,
                'level_code' => $term->academicLevel->level_code,
                'level_name' => $term->academicLevel->level_name,
                'level_order' => $term->academicLevel->level_order,
            ] : null,
        ];
    }

    private function assertTermIdentity(int $academicYearId, int $semesterId): void
    {
        AcademicYear::query()->findOrFail($academicYearId);
        Semester::query()->findOrFail($semesterId);
    }

    private function assertSchemaReady(): void
    {
        if (! AcademicRecordWorkflow::schemaReady()) {
            throw AcademicRecordException::academicTermWorkflowRequired();
        }
    }

    private function assertCanView(User $user): void
    {
        if (! $user->hasPermission(AcademicRecordWorkflow::PERMISSION_RECORDS_VIEW)) {
            throw new AccessDeniedHttpException('Academic record view permission is required.');
        }
    }

    private function assertCanFinalize(User $user): void
    {
        if (! $user->isRegistrationOfficer()
            || ! $user->effectivePermissions()->contains(AcademicRecordWorkflow::PERMISSION_RECORDS_FINALIZE)) {
            throw new AccessDeniedHttpException(
                'Only a registration officer with assigned academic-record finalize permission may finalize term snapshots.'
            );
        }
    }

    private function assertCanAccessStudent(User $user, Student $student): void
    {
        if (! $this->dataScopes->canAccessStudent($user, $student)) {
            throw new AccessDeniedHttpException('You are not authorized to access this student.');
        }
    }
}
