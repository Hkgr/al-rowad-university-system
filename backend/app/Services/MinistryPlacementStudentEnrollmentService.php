<?php

namespace App\Services;

use App\Exceptions\MinistryPlacementException;
use App\Models\AcademicLevel;
use App\Models\AcademicProgram;
use App\Models\AdmissionApplication;
use App\Models\Applicant;
use App\Models\College;
use App\Models\Department;
use App\Models\MinistryPlacementBatch;
use App\Models\MinistryPlacementRecord;
use App\Models\Student;
use App\Models\StudentStatus;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Support\MinistryPlacementNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MinistryPlacementStudentEnrollmentService
{
    private const PENDING = 'pending';

    private const ACCEPTED = 'accepted';

    private const REJECTED = 'rejected';

    /** @return array<string, mixed> */
    public function summary(int $batchId): array
    {
        $batch = MinistryPlacementBatch::query()->with('academicYear')->findOrFail($batchId);
        $records = $this->recordsQuery($batchId)->get();
        $analysis = $this->analyse($batch, $records);

        return $this->summaryPayload($batch, $analysis);
    }

    /** @return array<int, array<string, mixed>> */
    public function academicLevels(): array
    {
        return AcademicLevel::query()
            ->where('is_active', true)
            ->orderBy('level_order')
            ->orderBy('academic_level_id')
            ->get(['academic_level_id', 'level_code', 'level_name', 'level_order'])
            ->map(fn (AcademicLevel $level): array => [
                'academic_level_id' => (int) $level->academic_level_id,
                'level_code' => $level->level_code,
                'level_name' => $level->level_name,
                'level_order' => (int) $level->level_order,
            ])->all();
    }

    /** @param array{student_number: string, current_academic_level_id: int, enrollment_date: string} $input @return array<string, mixed> */
    public function enroll(int $recordId, array $input, User $actor): array
    {
        return DB::transaction(function () use ($recordId, $input, $actor): array {
            $record = MinistryPlacementRecord::query()->lockForUpdate()->findOrFail($recordId);
            $batch = MinistryPlacementBatch::query()->with('academicYear')->lockForUpdate()->findOrFail((int) $record->batch_id);
            $records = collect([$record]);
            $this->loadLockedApplicants($records);
            $this->loadLockedProgramHierarchy($records);
            $item = $this->analyse($batch, $records, true)['items']->sole();

            if ($item['enrollment_state'] === 'enrolled') {
                return [
                    'created' => false,
                    'enrollment' => $this->recordPayload($item, $batch),
                ];
            }
            if ($item['enrollment_state'] !== 'ready') {
                $this->throwForBlocker($item);
            }

            $level = AcademicLevel::query()
                ->whereKey((int) $input['current_academic_level_id'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();
            if ($level === null) {
                throw MinistryPlacementException::conversionConflict(
                    'ministry_placement_academic_level_unavailable',
                    'المستوى الأكاديمي المحدد غير نشط أو غير متاح.',
                    [],
                    422,
                );
            }

            $studentStatus = $this->activeStudentStatus(true);
            $studentNumber = $this->studentNumber($input['student_number']);
            /** @var Applicant $applicant */
            $applicant = $item['applicant'];
            /** @var AdmissionApplication $application */
            $application = $item['application'];
            $this->assertStudentConflictsAbsent($studentNumber, $applicant->email);

            $operationDate = CarbonImmutable::now('UTC')->toDateString();
            $application->forceFill([
                'decision_status' => self::ACCEPTED,
                'decision_date' => $operationDate,
                'decided_by_user_id' => (int) $actor->user_id,
            ])->save();

            $student = $this->createStudent(
                $studentNumber,
                $application,
                $applicant,
                (int) $level->academic_level_id,
                (string) $input['enrollment_date'],
                (int) $studentStatus->student_status_id,
            );

            $record->forceFill(['processing_status' => 'enrolled'])->save();
            $this->audit($actor, 'ministry_placement.student_enroll', [
                'record_id' => (int) $record->placement_record_id,
                'batch_id' => (int) $record->batch_id,
                'applicant_id' => (int) $applicant->applicant_id,
                'admission_application_id' => (int) $application->admission_application_id,
                'student_id' => (int) $student->student_id,
                'academic_program_id' => (int) $application->academic_program_id,
                'academic_level_id' => (int) $level->academic_level_id,
            ]);

            $item['record'] = $record;
            $item['application'] = $application;
            $item['student'] = $student->setRelation('currentAcademicLevel', $level)->setRelation('studentStatus', $studentStatus);
            $item['enrollment_state'] = 'enrolled';
            $item['blocker_code'] = null;

            return [
                'created' => true,
                'enrollment' => $this->recordPayload($item, $batch),
            ];
        });
    }

    /**
     * @param array<int, array{placement_record_id: int, student_number: string, current_academic_level_id: int, enrollment_date: string}> $inputs
     * @return array<string, mixed>
     */
    public function enrollAll(int $batchId, int $expectedEligibleCount, string $expectedSnapshot, array $inputs, User $actor): array
    {
        return DB::transaction(function () use ($batchId, $expectedEligibleCount, $expectedSnapshot, $inputs, $actor): array {
            $batch = MinistryPlacementBatch::query()->with('academicYear')->lockForUpdate()->findOrFail($batchId);
            $records = MinistryPlacementRecord::query()
                ->where('batch_id', $batchId)
                ->orderBy('placement_record_id')
                ->lockForUpdate()
                ->get();
            $this->loadLockedApplicants($records);
            $this->loadLockedProgramHierarchy($records);
            $analysis = $this->analyse($batch, $records, true);
            $eligible = $analysis['items']->where('enrollment_state', 'ready')->values();
            $snapshot = $this->snapshot($eligible, (int) $batch->academic_year_id);
            $inputCollection = collect($inputs)->keyBy(fn (array $input): int => (int) $input['placement_record_id']);
            $eligibleIds = $eligible->pluck('record.placement_record_id')->map(fn ($id): int => (int) $id)->sort()->values();
            $inputIds = $inputCollection->keys()->map(fn ($id): int => (int) $id)->sort()->values();

            if ($eligible->count() !== $expectedEligibleCount
                || count($inputs) !== $expectedEligibleCount
                || $inputCollection->count() !== count($inputs)
                || $eligibleIds->all() !== $inputIds->all()
                || ! hash_equals($snapshot, $expectedSnapshot)) {
                throw MinistryPlacementException::conversionConflict(
                    'ministry_placement_enrollment_batch_stale',
                    'تغيرت السجلات الجاهزة لإنشاء الطلاب. حدّث البيانات ثم راجع العملية وأكدها مجدداً.',
                );
            }

            $levelIds = $inputCollection->pluck('current_academic_level_id')->map(fn ($id): int => (int) $id)->unique()->values();
            $levels = AcademicLevel::query()
                ->whereIn('academic_level_id', $levelIds->all())
                ->where('is_active', true)
                ->orderBy('academic_level_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('academic_level_id');
            if ($levels->count() !== $levelIds->count()) {
                throw MinistryPlacementException::conversionConflict(
                    'ministry_placement_academic_level_unavailable',
                    'أحد المستويات الأكاديمية المحددة غير نشط أو غير متاح.',
                    [],
                    422,
                );
            }

            $studentStatus = $this->activeStudentStatus(true);
            $prepared = $eligible->map(function (array $item) use ($inputCollection): array {
                /** @var MinistryPlacementRecord $record */
                $record = $item['record'];
                $input = $inputCollection->get((int) $record->placement_record_id);
                $input['student_number'] = $this->studentNumber($input['student_number']);

                return ['item' => $item, 'input' => $input];
            })->values();

            $numberKeys = $prepared->pluck('input.student_number')->map(fn (string $number): string => mb_strtolower($number, 'UTF-8'));
            if ($numberKeys->unique()->count() !== $numberKeys->count()) {
                throw MinistryPlacementException::conversionConflict(
                    'ministry_placement_student_number_conflict',
                    'تتضمن العملية أرقاماً طلابية مكررة.',
                );
            }
            $numbers = $prepared->pluck('input.student_number')->values();
            if (Student::withTrashed()->whereIn('student_number', $numbers->all())->exists()) {
                throw MinistryPlacementException::conversionConflict(
                    'ministry_placement_student_number_conflict',
                    'أحد أرقام الطلاب مستخدم مسبقاً.',
                );
            }

            $emails = $prepared->pluck('item.applicant.email')
                ->map(fn ($email): ?string => MinistryPlacementNormalizer::text($email))
                ->filter()
                ->values();
            $emailKeys = $emails->map(fn (string $email): string => mb_strtolower($email, 'UTF-8'));
            if ($emailKeys->unique()->count() !== $emailKeys->count()
                || ($emails->isNotEmpty() && Student::withTrashed()->whereIn('email', $emails->all())->exists())) {
                throw MinistryPlacementException::conversionConflict(
                    'ministry_placement_student_email_conflict',
                    'أحد عناوين البريد الإلكتروني مرتبط بطالب موجود أو مكرر ضمن العملية.',
                );
            }

            $operationDate = CarbonImmutable::now('UTC')->toDateString();
            foreach ($prepared as $preparedItem) {
                $item = $preparedItem['item'];
                $input = $preparedItem['input'];
                /** @var MinistryPlacementRecord $record */
                $record = $item['record'];
                /** @var Applicant $applicant */
                $applicant = $item['applicant'];
                /** @var AdmissionApplication $application */
                $application = $item['application'];
                $application->forceFill([
                    'decision_status' => self::ACCEPTED,
                    'decision_date' => $operationDate,
                    'decided_by_user_id' => (int) $actor->user_id,
                ])->save();
                $this->createStudent(
                    (string) $input['student_number'],
                    $application,
                    $applicant,
                    (int) $input['current_academic_level_id'],
                    (string) $input['enrollment_date'],
                    (int) $studentStatus->student_status_id,
                );
                $record->forceFill(['processing_status' => 'enrolled'])->save();
            }

            if ($prepared->isNotEmpty()) {
                $this->audit($actor, 'ministry_placement.student_enroll_bulk', [
                    'batch_id' => (int) $batch->batch_id,
                    'academic_year_id' => (int) $batch->academic_year_id,
                    'enrolled_count' => $prepared->count(),
                ]);
            }

            return [
                'batch_id' => (int) $batch->batch_id,
                'academic_year_id' => (int) $batch->academic_year_id,
                'enrolled_count' => $prepared->count(),
            ];
        });
    }

    private function recordsQuery(int $batchId): Builder
    {
        return MinistryPlacementRecord::query()
            ->where('batch_id', $batchId)
            ->with(['matchedAcademicProgram.department.college', 'applicant'])
            ->orderBy('placement_record_id');
    }

    /** @param Collection<int, MinistryPlacementRecord> $records */
    private function loadLockedApplicants(Collection $records): void
    {
        $ids = $records->pluck('applicant_id')->filter()->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        $applicants = Applicant::query()->whereIn('applicant_id', $ids->all())->orderBy('applicant_id')->lockForUpdate()->get()->keyBy('applicant_id');
        foreach ($records as $record) {
            $record->setRelation('applicant', $record->applicant_id === null ? null : $applicants->get((int) $record->applicant_id));
        }
    }

    /** @param Collection<int, MinistryPlacementRecord> $records */
    private function loadLockedProgramHierarchy(Collection $records): void
    {
        $programIds = $records->pluck('matched_academic_program_id')->filter()->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        $programs = AcademicProgram::query()->whereIn('academic_program_id', $programIds->all())->orderBy('academic_program_id')->lockForUpdate()->get();
        $departmentIds = $programs->pluck('department_id')->filter()->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        $departments = Department::query()->whereIn('department_id', $departmentIds->all())->orderBy('department_id')->lockForUpdate()->get();
        $collegeIds = $departments->pluck('college_id')->filter()->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        $colleges = College::query()->whereIn('college_id', $collegeIds->all())->orderBy('college_id')->lockForUpdate()->get()->keyBy('college_id');
        $departments->each(fn (Department $department) => $department->setRelation('college', $colleges->get((int) $department->college_id)));
        $departments = $departments->keyBy('department_id');
        $programs->each(fn (AcademicProgram $program) => $program->setRelation('department', $departments->get((int) $program->department_id)));
        $programs = $programs->keyBy('academic_program_id');
        foreach ($records as $record) {
            $record->setRelation('matchedAcademicProgram', $record->matched_academic_program_id === null ? null : $programs->get((int) $record->matched_academic_program_id));
        }
    }

    /** @return array{items: Collection<int, array<string, mixed>>, identity_conflict_records: int} */
    private function analyse(MinistryPlacementBatch $batch, Collection $records, bool $lockRelated = false): array
    {
        $identityReferences = $this->identityReferences($lockRelated);
        $applicantIds = $records->pluck('applicant_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        $applicationQuery = AdmissionApplication::query()->whereIn('applicant_id', $applicantIds->all())->orderBy('admission_application_id');
        if ($lockRelated) {
            $applicationQuery->lockForUpdate();
        }
        $applications = $applicantIds->isEmpty() ? collect() : $applicationQuery->get();
        $applicationsByContext = $applications->groupBy(fn (AdmissionApplication $application): string => implode(':', [
            (int) $application->applicant_id,
            (int) $application->academic_program_id,
            (int) $application->academic_year_id,
        ]));
        $applicationIds = $applications->pluck('admission_application_id')->map(fn ($id): int => (int) $id)->values();
        $studentQuery = Student::withTrashed()
            ->with(['currentAcademicLevel', 'studentStatus'])
            ->whereIn('admission_application_id', $applicationIds->all())
            ->orderBy('student_id');
        if ($lockRelated) {
            $studentQuery->lockForUpdate();
        }
        $students = $applicationIds->isEmpty() ? collect() : $studentQuery->get();
        $studentsByApplication = $students->groupBy(fn (Student $student): int => (int) $student->admission_application_id);

        $items = $records->map(function (MinistryPlacementRecord $record) use ($batch, $identityReferences, $applicationsByContext, $studentsByApplication): array {
            $identityKey = MinistryPlacementNormalizer::duplicateKey($record->national_civil_id);
            $references = $identityKey === null ? [] : ($identityReferences[$identityKey] ?? []);
            $identityConflict = count($references) > 1;
            $base = [
                'record' => $record,
                'applicant' => $record->applicant,
                'application' => null,
                'student' => null,
                'enrollment_state' => 'inconsistent',
                'blocker_code' => null,
                'identity_conflict' => $identityConflict,
                'identity_conflicts' => $identityConflict ? array_values(array_filter(
                    $references,
                    fn (array $reference): bool => $reference['placement_record_id'] !== (int) $record->placement_record_id,
                )) : [],
            ];

            if ($record->applicant_id === null) {
                return array_replace($base, [
                    'enrollment_state' => in_array((string) $record->processing_status, ['imported', 'program_matched'], true) ? 'not_ready' : 'inconsistent',
                    'blocker_code' => 'applicant_not_created',
                ]);
            }
            if ($record->applicant === null) {
                return array_replace($base, ['blocker_code' => 'linked_applicant_missing']);
            }
            if ($record->matched_academic_program_id === null || $batch->academicYear === null) {
                return array_replace($base, ['blocker_code' => 'application_context_mismatch']);
            }

            $contextKey = implode(':', [(int) $record->applicant_id, (int) $record->matched_academic_program_id, (int) $batch->academic_year_id]);
            $expectedApplications = $applicationsByContext->get($contextKey, collect());
            if ($expectedApplications->count() !== 1) {
                return array_replace($base, ['blocker_code' => $expectedApplications->isEmpty() ? 'expected_application_missing' : 'expected_application_ambiguous']);
            }
            /** @var AdmissionApplication $application */
            $application = $expectedApplications->sole();
            $studentsForApplication = $studentsByApplication->get((int) $application->admission_application_id, collect());
            if ($studentsForApplication->count() > 1) {
                return array_replace($base, ['application' => $application, 'blocker_code' => 'application_student_ambiguous']);
            }
            /** @var Student|null $student */
            $student = $studentsForApplication->first();
            $base = array_replace($base, ['application' => $application, 'student' => $student]);
            if ($student !== null && $student->deleted_at !== null) {
                return array_replace($base, ['blocker_code' => 'student_deleted']);
            }
            if ($student !== null && ($student->currentAcademicLevel === null || $student->studentStatus === null)) {
                return array_replace($base, ['blocker_code' => 'student_reference_missing']);
            }

            $decision = trim((string) $application->decision_status);
            if (! in_array($decision, [self::PENDING, self::ACCEPTED, self::REJECTED], true)) {
                return array_replace($base, ['blocker_code' => 'decision_status_unsupported']);
            }
            if ($decision === self::PENDING && ($application->decision_date !== null || $application->decided_by_user_id !== null)) {
                return array_replace($base, ['blocker_code' => 'decision_provenance_inconsistent']);
            }
            if (in_array($decision, [self::ACCEPTED, self::REJECTED], true)
                && ($application->decision_date === null || $application->decided_by_user_id === null)) {
                return array_replace($base, ['blocker_code' => 'decision_provenance_inconsistent']);
            }

            if ($decision === self::PENDING) {
                if ($student !== null) {
                    return array_replace($base, ['blocker_code' => 'student_with_nonaccepted_application']);
                }
                if (! $this->programIsActive($record)) {
                    return array_replace($base, ['blocker_code' => 'program_hierarchy_inactive']);
                }
                if ($identityKey === null) {
                    return array_replace($base, ['blocker_code' => 'identity_missing']);
                }
                if ($identityConflict) {
                    return array_replace($base, ['blocker_code' => 'identity_conflict']);
                }
                if ($record->processing_status === 'applicant_created') {
                    return array_replace($base, ['enrollment_state' => 'ready']);
                }
                if ($record->processing_status === 'documents_pending') {
                    return array_replace($base, ['enrollment_state' => 'not_ready', 'blocker_code' => 'documents_pending']);
                }

                return array_replace($base, ['blocker_code' => 'processing_status_inconsistent']);
            }

            if ($decision === self::REJECTED) {
                if ($student !== null) {
                    return array_replace($base, ['blocker_code' => 'student_with_nonaccepted_application']);
                }
                if (in_array((string) $record->processing_status, ['applicant_created', 'documents_pending', 'rejected'], true)) {
                    return array_replace($base, ['enrollment_state' => 'rejected']);
                }

                return array_replace($base, ['blocker_code' => 'processing_status_inconsistent']);
            }

            if ($student === null) {
                return array_replace($base, ['blocker_code' => 'accepted_without_student']);
            }
            if ((int) $student->academic_program_id !== (int) $application->academic_program_id) {
                return array_replace($base, ['blocker_code' => 'student_program_mismatch']);
            }
            if ($record->processing_status !== 'enrolled') {
                return array_replace($base, ['blocker_code' => 'processing_status_inconsistent']);
            }

            return array_replace($base, ['enrollment_state' => 'enrolled']);
        })->values();

        return [
            'items' => $items,
            'identity_conflict_records' => $items->where('identity_conflict', true)->count(),
        ];
    }

    /** @return array<string, array<int, array<string, int|null>>> */
    private function identityReferences(bool $lock): array
    {
        $references = [];
        $query = MinistryPlacementRecord::query()
            ->select(['placement_record_id', 'batch_id', 'national_civil_id', 'applicant_id'])
            ->orderBy('placement_record_id');
        if ($lock) {
            $query->lockForUpdate();
        }
        foreach ($query->get() as $record) {
            $key = MinistryPlacementNormalizer::duplicateKey($record->national_civil_id);
            if ($key !== null) {
                $references[$key][] = [
                    'placement_record_id' => (int) $record->placement_record_id,
                    'batch_id' => (int) $record->batch_id,
                    'applicant_id' => $record->applicant_id === null ? null : (int) $record->applicant_id,
                ];
            }
        }

        return $references;
    }

    private function programIsActive(MinistryPlacementRecord $record): bool
    {
        $program = $record->matchedAcademicProgram;

        return $program !== null && $program->is_active
            && $program->department !== null && $program->department->is_active
            && $program->department->college !== null && $program->department->college->is_active;
    }

    /** @param array{items: Collection<int, array<string, mixed>>, identity_conflict_records: int} $analysis @return array<string, mixed> */
    private function summaryPayload(MinistryPlacementBatch $batch, array $analysis): array
    {
        $items = $analysis['items'];
        $eligible = $items->where('enrollment_state', 'ready')->values();

        return [
            'batch_id' => (int) $batch->batch_id,
            'academic_year_id' => (int) $batch->academic_year_id,
            'academic_year' => $batch->academicYear === null ? null : [
                'academic_year_id' => (int) $batch->academicYear->academic_year_id,
                'year_name' => $batch->academicYear->year_name,
            ],
            'metrics' => [
                'total_records' => $items->count(),
                'ready_records' => $eligible->count(),
                'enrolled_records' => $items->where('enrollment_state', 'enrolled')->count(),
                'not_ready_records' => $items->where('enrollment_state', 'not_ready')->count(),
                'rejected_records' => $items->where('enrollment_state', 'rejected')->count(),
                'inconsistent_records' => $items->where('enrollment_state', 'inconsistent')->count(),
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
        $canonical = $eligible->sortBy(fn (array $item): int => (int) $item['record']->placement_record_id)
            ->map(fn (array $item): string => implode(':', [
                (int) $item['record']->placement_record_id,
                (int) $item['applicant']->applicant_id,
                (int) $item['application']->admission_application_id,
                (int) $item['record']->matched_academic_program_id,
                $academicYearId,
            ]))->implode("\n");

        return hash('sha256', $canonical);
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function recordPayload(array $item, MinistryPlacementBatch $batch): array
    {
        /** @var MinistryPlacementRecord $record */
        $record = $item['record'];
        /** @var Applicant|null $applicant */
        $applicant = $item['applicant'];
        /** @var AdmissionApplication|null $application */
        $application = $item['application'];
        /** @var Student|null $student */
        $student = $item['student'];
        $program = $record->matchedAcademicProgram;

        return [
            'placement_record_id' => (int) $record->placement_record_id,
            'processing_status' => $record->processing_status,
            'enrollment_state' => $item['enrollment_state'],
            'blocker_code' => $item['blocker_code'],
            'identity_conflict' => $item['identity_conflict'],
            'identity_conflicts' => $item['identity_conflicts'],
            'applicant' => $applicant === null ? null : [
                'applicant_id' => (int) $applicant->applicant_id,
                'applicant_number' => $applicant->applicant_number,
                'full_name' => trim($applicant->first_name.' '.$applicant->last_name),
            ],
            'academic_program' => $program === null ? null : [
                'academic_program_id' => (int) $program->academic_program_id,
                'program_code' => $program->program_code,
                'program_name' => $program->program_name,
            ],
            'academic_year' => $batch->academicYear === null ? null : [
                'academic_year_id' => (int) $batch->academicYear->academic_year_id,
                'year_name' => $batch->academicYear->year_name,
            ],
            'admission_application' => $application === null ? null : [
                'admission_application_id' => (int) $application->admission_application_id,
                'decision_status' => $application->decision_status,
                'decision_date' => $application->decision_date?->toDateString(),
            ],
            'student' => $student === null ? null : [
                'student_id' => (int) $student->student_id,
                'student_number' => $student->student_number,
                'academic_program_id' => (int) $student->academic_program_id,
                'enrollment_date' => $student->enrollment_date?->toDateString(),
                'current_academic_level' => $student->currentAcademicLevel === null ? null : [
                    'academic_level_id' => (int) $student->currentAcademicLevel->academic_level_id,
                    'level_code' => $student->currentAcademicLevel->level_code,
                    'level_name' => $student->currentAcademicLevel->level_name,
                ],
                'student_status' => $student->studentStatus === null ? null : [
                    'student_status_id' => (int) $student->studentStatus->student_status_id,
                    'status_code' => $student->studentStatus->status_code,
                    'status_name' => $student->studentStatus->status_name,
                ],
            ],
        ];
    }

    private function activeStudentStatus(bool $lock): StudentStatus
    {
        $query = StudentStatus::query()->where('status_code', 'active')->where('is_active', true)->orderBy('student_status_id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $statuses = $query->get();
        if ($statuses->count() !== 1) {
            throw MinistryPlacementException::conversionConflict(
                'ministry_placement_student_status_configuration_invalid',
                'إعداد حالة الطالب النشطة غير متسق.',
            );
        }

        return $statuses->sole();
    }

    private function studentNumber(mixed $value): string
    {
        $number = MinistryPlacementNormalizer::text($value);
        if ($number === null || mb_strlen($number, 'UTF-8') > 50) {
            throw MinistryPlacementException::conversionConflict(
                'ministry_placement_student_number_invalid',
                'رقم الطالب مطلوب ويجب ألا يتجاوز 50 محرفاً.',
                [],
                422,
            );
        }

        return $number;
    }

    private function assertStudentConflictsAbsent(string $studentNumber, ?string $email): void
    {
        if (Student::withTrashed()->where('student_number', $studentNumber)->exists()) {
            throw MinistryPlacementException::conversionConflict(
                'ministry_placement_student_number_conflict',
                'رقم الطالب مستخدم مسبقاً.',
            );
        }
        $email = MinistryPlacementNormalizer::text($email);
        if ($email !== null && Student::withTrashed()->where('email', $email)->exists()) {
            throw MinistryPlacementException::conversionConflict(
                'ministry_placement_student_email_conflict',
                'البريد الإلكتروني للمتقدم مرتبط بطالب موجود.',
            );
        }
    }

    private function createStudent(string $number, AdmissionApplication $application, Applicant $applicant, int $levelId, string $enrollmentDate, int $statusId): Student
    {
        try {
            return Student::query()->create([
                'student_number' => $number,
                'admission_application_id' => (int) $application->admission_application_id,
                'first_name' => $applicant->first_name,
                'last_name' => $applicant->last_name,
                'father_name' => $applicant->father_name,
                'mother_name' => $applicant->mother_name,
                'date_of_birth' => $applicant->date_of_birth?->toDateString(),
                'gender' => $applicant->gender,
                'phone_number' => $applicant->phone_number,
                'email' => MinistryPlacementNormalizer::text($applicant->email),
                'address' => $applicant->address,
                'nationality' => $applicant->nationality,
                'academic_program_id' => (int) $application->academic_program_id,
                'current_academic_level_id' => $levelId,
                'enrollment_date' => $enrollmentDate,
                'student_status_id' => $statusId,
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                $message = mb_strtolower($exception->getMessage(), 'UTF-8');
                $code = str_contains($message, 'email')
                    ? 'ministry_placement_student_email_conflict'
                    : (str_contains($message, 'admission_application')
                        ? 'ministry_placement_enrollment_inconsistent'
                        : 'ministry_placement_student_number_conflict');
                throw MinistryPlacementException::conversionConflict($code, 'تعذر إنشاء الطالب بسبب تعارض مع سجل طالب موجود.');
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $item */
    private function throwForBlocker(array $item): never
    {
        $code = match ($item['blocker_code']) {
            'program_hierarchy_inactive' => 'ministry_placement_enrollment_program_stale',
            'identity_missing' => 'ministry_placement_identity_missing',
            'identity_conflict' => 'ministry_placement_identity_conflict',
            default => $item['enrollment_state'] === 'not_ready'
                ? 'ministry_placement_enrollment_not_ready'
                : 'ministry_placement_enrollment_inconsistent',
        };
        $errors = $code === 'ministry_placement_identity_conflict'
            ? ['conflicts' => $item['identity_conflicts']]
            : ['blocker_code' => [$item['blocker_code']]];
        throw MinistryPlacementException::conversionConflict(
            $code,
            $code === 'ministry_placement_enrollment_not_ready'
                ? 'سجل المفاضلة غير جاهز لاعتماد طلب القبول وإنشاء الطالب.'
                : 'حالة سجل المفاضلة أو طلب القبول غير متسقة وتحتاج إلى مراجعة.',
            $errors,
        );
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
            'description' => json_encode($description, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => CarbonImmutable::now('UTC'),
        ]);
    }
}
