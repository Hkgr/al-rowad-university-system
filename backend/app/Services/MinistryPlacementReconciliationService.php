<?php

namespace App\Services;

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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

final class MinistryPlacementReconciliationService
{
    private const PROCESSING_STATUSES = [
        'imported', 'program_matched', 'applicant_created', 'documents_pending',
        'accepted', 'enrolled', 'rejected',
    ];

    private const DECISION_STATUSES = ['pending', 'accepted', 'rejected'];

    private const TERMINAL_STATES = ['enrolled', 'rejected'];

    private const AUDIT_ACTIONS = [
        'ministry_placement.import',
        'ministry_placement.program_match',
        'ministry_placement.program_unmatch',
        'ministry_placement.program_match_bulk',
        'ministry_placement.applicant_convert',
        'ministry_placement.applicant_convert_bulk',
        'ministry_placement.student_enroll',
        'ministry_placement.student_enroll_bulk',
    ];

    private const ISSUE_ORDER = [
        'ministry_processing_status_unsupported',
        'identity_conflict_multiple_terminal_records',
        'identity_conflict',
        'identity_missing',
        'program_match_missing',
        'program_reference_missing',
        'program_hierarchy_inactive',
        'applicant_link_missing',
        'orphan_expected_applicant',
        'orphan_expected_application',
        'orphan_expected_student',
        'linked_applicant_missing',
        'applicant_number_mismatch',
        'application_context_mismatch',
        'expected_application_missing',
        'expected_application_ambiguous',
        'admission_decision_status_unsupported',
        'pending_decision_has_provenance',
        'final_decision_missing_provenance',
        'decision_actor_missing',
        'application_student_ambiguous',
        'accepted_without_student',
        'student_with_pending_application',
        'student_with_rejected_application',
        'student_soft_deleted',
        'student_program_mismatch',
        'student_academic_level_missing',
        'student_status_missing',
        'ministry_state_chain_mismatch',
        'historical_program_hierarchy_inactive',
        'student_academic_level_inactive',
        'student_status_inactive',
        'identity_conflict_terminal_record',
        'identity_missing_terminal_record',
    ];

    private const ISSUE_MESSAGES = [
        'ministry_processing_status_unsupported' => 'حالة معالجة سجل المفاضلة غير مدعومة.',
        'identity_missing' => 'الهوية الوزارية المعيارية غير متاحة.',
        'identity_conflict' => 'الهوية الوزارية المعيارية مكررة في سجل آخر قابل للتقدم.',
        'identity_conflict_multiple_terminal_records' => 'توجد عدة سلاسل نهائية مستقلة للهوية الوزارية المعيارية نفسها.',
        'identity_conflict_terminal_record' => 'توجد هوية مماثلة في سجل غير نهائي يحتاج معالجة.',
        'identity_missing_terminal_record' => 'الهوية الوزارية مفقودة من سلسلة نهائية تاريخية متسقة.',
        'program_match_missing' => 'مطابقة البرنامج الأكاديمي المطلوبة غير موجودة.',
        'program_reference_missing' => 'مرجع البرنامج أو القسم أو الكلية غير موجود.',
        'program_hierarchy_inactive' => 'البرنامج أو القسم أو الكلية غير نشط قبل اكتمال المسار.',
        'historical_program_hierarchy_inactive' => 'أصبحت بنية البرنامج غير نشطة بعد اكتمال السلسلة التاريخية.',
        'applicant_link_missing' => 'مرحلة السجل تتطلب رابط متقدم صالحاً.',
        'orphan_expected_applicant' => 'يوجد متقدم يحمل الرقم الحتمي المتوقع لكنه غير مرتبط بسجل المفاضلة.',
        'orphan_expected_application' => 'يوجد طلب قبول تابع للمتقدم الحتمي اليتيم دون رابط مفاضلة متين.',
        'orphan_expected_student' => 'يوجد طالب تابع لطلب القبول اليتيم دون رابط مفاضلة متين.',
        'linked_applicant_missing' => 'معرف المتقدم المرتبط لا يشير إلى سجل موجود.',
        'applicant_number_mismatch' => 'رقم المتقدم المرتبط لا يطابق الرقم الحتمي لسجل المفاضلة.',
        'application_context_mismatch' => 'سياق البرنامج أو السنة اللازم لطلب القبول غير مكتمل.',
        'expected_application_missing' => 'طلب القبول المطابق للمتقدم والبرنامج والسنة غير موجود.',
        'expected_application_ambiguous' => 'يوجد أكثر من طلب قبول مطابق للسياق نفسه.',
        'admission_decision_status_unsupported' => 'حالة قرار طلب القبول غير مدعومة.',
        'pending_decision_has_provenance' => 'طلب القبول المعلق يحتوي بيانات قرار نهائي.',
        'final_decision_missing_provenance' => 'قرار القبول النهائي يفتقد التاريخ أو المنفذ.',
        'decision_actor_missing' => 'منفذ قرار القبول المشار إليه غير موجود.',
        'accepted_without_student' => 'طلب القبول مقبول دون سجل طالب مرتبط.',
        'student_with_pending_application' => 'يوجد طالب مرتبط بطلب قبول ما زال معلقاً.',
        'student_with_rejected_application' => 'يوجد طالب مرتبط بطلب قبول مرفوض.',
        'application_student_ambiguous' => 'يوجد أكثر من طالب مرتبط بطلب القبول نفسه.',
        'student_soft_deleted' => 'سجل الطالب المرتبط محذوف حذفاً منطقياً.',
        'student_program_mismatch' => 'برنامج الطالب لا يطابق برنامج طلب القبول.',
        'student_academic_level_missing' => 'مرجع المستوى الأكاديمي الحالي للطالب غير موجود.',
        'student_status_missing' => 'مرجع حالة الطالب غير موجود.',
        'student_academic_level_inactive' => 'تعريف المستوى الأكاديمي التاريخي لم يعد نشطاً.',
        'student_status_inactive' => 'تعريف حالة الطالب التاريخية لم يعد نشطاً.',
        'ministry_state_chain_mismatch' => 'حالة سجل المفاضلة لا تطابق سلسلة البيانات الفعلية.',
    ];

    /** @return list<string> */
    public static function issueCodes(): array
    {
        return self::ISSUE_ORDER;
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function globalSummary(array $filters): array
    {
        $analysis = $this->analyse(isset($filters['batch_id']) ? (int) $filters['batch_id'] : null);
        $payload = $this->payload($analysis, $filters);
        $payload['batch_count'] = $analysis['batches']->count();
        $payload['reconciliation_checksum'] = $payload['checksum'];
        unset($payload['checksum']);
        $payload['audit_coverage'] = $this->auditCoverage();

        return $payload;
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function batchSummary(int $batchId, array $filters): array
    {
        $analysis = $this->analyse($batchId);
        /** @var MinistryPlacementBatch $batch */
        $batch = $analysis['batches']->sole();
        $payload = $this->payload($analysis, $filters);

        return [
            'batch_id' => (int) $batch->batch_id,
            'academic_year_id' => (int) $batch->academic_year_id,
            ...$payload,
        ];
    }

    /** @return array{batches: Collection<int, MinistryPlacementBatch>, items: Collection<int, array<string, mixed>>} */
    private function analyse(?int $batchId): array
    {
        $allBatches = MinistryPlacementBatch::query()->with('academicYear')->orderBy('batch_id')->get();
        $batches = $batchId === null
            ? $allBatches
            : $allBatches->where('batch_id', $batchId)->values();
        if ($batchId !== null && $batches->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(MinistryPlacementBatch::class, [$batchId]);
        }
        $batchMap = $allBatches->keyBy('batch_id');

        $records = $allBatches->isEmpty() ? collect() : MinistryPlacementRecord::query()
            ->whereIn('batch_id', $allBatches->pluck('batch_id')->all())
            ->orderBy('batch_id')
            ->orderBy('placement_record_id')
            ->get();

        $identityGroups = $records->groupBy(fn (MinistryPlacementRecord $record): string =>
            MinistryPlacementNormalizer::duplicateKey($record->national_civil_id) ?? '__missing__'.(int) $record->placement_record_id);

        $linkedApplicantIds = $records->pluck('applicant_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        $expectedNumbers = $records->map(fn (MinistryPlacementRecord $record): string => $this->expectedApplicantNumber($record))->unique()->values();
        $applicants = $records->isEmpty() ? collect() : Applicant::query()
            ->where(function ($query) use ($linkedApplicantIds, $expectedNumbers): void {
                if ($linkedApplicantIds->isNotEmpty()) {
                    $query->whereIn('applicant_id', $linkedApplicantIds->all());
                }
                if ($expectedNumbers->isNotEmpty()) {
                    $method = $linkedApplicantIds->isEmpty() ? 'whereIn' : 'orWhereIn';
                    $query->{$method}('applicant_number', $expectedNumbers->all());
                }
            })
            ->orderBy('applicant_id')
            ->get();
        $applicantsById = $applicants->keyBy('applicant_id');
        $applicantsByNumber = $applicants->keyBy('applicant_number');

        $candidateApplicantIds = $applicants->pluck('applicant_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $applicationQuery = AdmissionApplication::query()
            ->whereIn('applicant_id', $candidateApplicantIds->all())
            ->orderBy('admission_application_id');
        $applications = $candidateApplicantIds->isEmpty() ? collect() : $applicationQuery->get();
        $applicationsByApplicant = $applications->groupBy(fn (AdmissionApplication $application): int => (int) $application->applicant_id);
        $applicationsByContext = $applications->groupBy(fn (AdmissionApplication $application): string => $this->applicationContextKey(
            (int) $application->applicant_id,
            (int) $application->academic_program_id,
            (int) $application->academic_year_id,
        ));

        $applicationIds = $applications->pluck('admission_application_id')->map(fn ($id): int => (int) $id)->values();
        $students = $applicationIds->isEmpty() ? collect() : Student::withTrashed()
            ->whereIn('admission_application_id', $applicationIds->all())
            ->orderBy('student_id')
            ->get();
        $studentsByApplication = $students->groupBy(fn (Student $student): int => (int) $student->admission_application_id);

        $programIds = $records->pluck('matched_academic_program_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        $programs = $programIds->isEmpty() ? collect() : AcademicProgram::query()->whereIn('academic_program_id', $programIds->all())->get();
        $departments = $programs->isEmpty() ? collect() : Department::query()->whereIn('department_id', $programs->pluck('department_id')->unique()->all())->get();
        $colleges = $departments->isEmpty() ? collect() : College::query()->whereIn('college_id', $departments->pluck('college_id')->unique()->all())->get();
        $programs = $programs->keyBy('academic_program_id');
        $departments = $departments->keyBy('department_id');
        $colleges = $colleges->keyBy('college_id');

        $levels = AcademicLevel::query()->whereIn('academic_level_id', $students->pluck('current_academic_level_id')->filter()->unique()->all())->get()->keyBy('academic_level_id');
        $statuses = StudentStatus::query()->whereIn('student_status_id', $students->pluck('student_status_id')->filter()->unique()->all())->get()->keyBy('student_status_id');
        $decisionUsers = User::query()->whereIn('user_id', $applications->pluck('decided_by_user_id')->filter()->unique()->all())->get(['user_id'])->keyBy('user_id');

        $items = $records->map(function (MinistryPlacementRecord $record) use (
            $batchMap, $applicantsById, $applicantsByNumber, $applicationsByApplicant, $applicationsByContext,
            $studentsByApplication, $programs, $departments, $colleges, $levels,
            $statuses, $decisionUsers
        ): array {
            /** @var MinistryPlacementBatch $batch */
            $batch = $batchMap->get((int) $record->batch_id);

            return $this->analyseRecord(
                $record,
                $batch,
                $applicantsById,
                $applicantsByNumber,
                $applicationsByApplicant,
                $applicationsByContext,
                $studentsByApplication,
                $programs,
                $departments,
                $colleges,
                $levels,
                $statuses,
                $decisionUsers,
            );
        })->keyBy('placement_record_id');

        $items = $this->applyIdentityIssues($items, $identityGroups);
        if ($batchId !== null) {
            $items = $items->where('batch_id', $batchId)->values();
        }

        return ['batches' => $batches, 'items' => $items->values()];
    }

    /** @return array<string, mixed> */
    private function analyseRecord(
        MinistryPlacementRecord $record,
        MinistryPlacementBatch $batch,
        Collection $applicantsById,
        Collection $applicantsByNumber,
        Collection $applicationsByApplicant,
        Collection $applicationsByContext,
        Collection $studentsByApplication,
        Collection $programs,
        Collection $departments,
        Collection $colleges,
        Collection $levels,
        Collection $statuses,
        Collection $decisionUsers,
    ): array {
        $issues = [];
        $processing = trim((string) $record->processing_status);
        $knownProcessing = in_array($processing, self::PROCESSING_STATUSES, true);
        if (! $knownProcessing) {
            $issues[] = $this->issue('ministry_processing_status_unsupported', 'blocked');
        }

        $program = $record->matched_academic_program_id === null ? null : $programs->get((int) $record->matched_academic_program_id);
        $department = $program === null ? null : $departments->get((int) $program->department_id);
        $college = $department === null ? null : $colleges->get((int) $department->college_id);
        $programReferenceComplete = $program !== null && $department !== null && $college !== null;
        $programActive = $programReferenceComplete && $program->is_active && $department->is_active && $college->is_active;

        if ($processing !== 'imported' && $record->matched_academic_program_id === null) {
            $issues[] = $this->issue('program_match_missing', 'blocked');
        } elseif ($record->matched_academic_program_id !== null && ! $programReferenceComplete) {
            $issues[] = $this->issue('program_reference_missing', 'blocked');
        }

        $expectedApplicantNumber = $this->expectedApplicantNumber($record);
        $expectedApplicant = $applicantsByNumber->get($expectedApplicantNumber);
        $applicant = $record->applicant_id === null ? null : $applicantsById->get((int) $record->applicant_id);
        $laterProcessing = in_array($processing, ['applicant_created', 'documents_pending', 'accepted', 'enrolled', 'rejected'], true);
        if ($record->applicant_id === null && ($laterProcessing || $expectedApplicant !== null)) {
            $issues[] = $this->issue('applicant_link_missing', 'blocked', $expectedApplicant === null ? [] : [
                'related_applicant_id' => (int) $expectedApplicant->applicant_id,
            ]);
        } elseif ($record->applicant_id !== null && $applicant === null) {
            $issues[] = $this->issue('linked_applicant_missing', 'blocked', ['related_applicant_id' => (int) $record->applicant_id]);
        } elseif ($applicant !== null && $applicant->applicant_number !== $expectedApplicantNumber) {
            $issues[] = $this->issue('applicant_number_mismatch', 'blocked', ['related_applicant_id' => (int) $applicant->applicant_id]);
        }

        if ($record->applicant_id === null && $expectedApplicant !== null) {
            $relatedApplicantId = (int) $expectedApplicant->applicant_id;
            $issues[] = $this->issue('orphan_expected_applicant', 'blocked', [
                'related_applicant_id' => $relatedApplicantId,
            ]);
            foreach ($applicationsByApplicant->get($relatedApplicantId, collect()) as $orphanApplication) {
                $relatedApplicationId = (int) $orphanApplication->admission_application_id;
                $issues[] = $this->issue('orphan_expected_application', 'blocked', [
                    'related_applicant_id' => $relatedApplicantId,
                    'related_application_id' => $relatedApplicationId,
                ]);
                foreach ($studentsByApplication->get($relatedApplicationId, collect()) as $orphanStudent) {
                    $issues[] = $this->issue('orphan_expected_student', 'blocked', [
                        'related_applicant_id' => $relatedApplicantId,
                        'related_application_id' => $relatedApplicationId,
                        'related_student_id' => (int) $orphanStudent->student_id,
                    ]);
                }
            }
        }

        $expectedApplications = collect();
        if ($applicant !== null && $record->matched_academic_program_id !== null && $batch->academicYear !== null) {
            $expectedApplications = $applicationsByContext->get($this->applicationContextKey(
                (int) $applicant->applicant_id,
                (int) $record->matched_academic_program_id,
                (int) $batch->academic_year_id,
            ), collect());
        } elseif ($laterProcessing || $applicant !== null) {
            $issues[] = $this->issue('application_context_mismatch', 'blocked');
        }

        $application = null;
        if ($applicant !== null && $laterProcessing) {
            if ($expectedApplications->isEmpty()) {
                $issues[] = $this->issue('expected_application_missing', 'blocked', ['related_applicant_id' => (int) $applicant->applicant_id]);
            } elseif ($expectedApplications->count() > 1) {
                foreach ($expectedApplications as $candidate) {
                    $issues[] = $this->issue('expected_application_ambiguous', 'blocked', [
                        'related_applicant_id' => (int) $applicant->applicant_id,
                        'related_application_id' => (int) $candidate->admission_application_id,
                    ]);
                }
            } else {
                $application = $expectedApplications->sole();
            }
        }

        $decision = $application === null ? null : trim((string) $application->decision_status);
        $students = $application === null ? collect() : $studentsByApplication->get((int) $application->admission_application_id, collect());
        $student = null;
        if ($application !== null) {
            if (! in_array($decision, self::DECISION_STATUSES, true)) {
                $issues[] = $this->issue('admission_decision_status_unsupported', 'blocked', $this->applicationRelated($application));
            } elseif ($decision === 'pending' && ($application->decision_date !== null || $application->decided_by_user_id !== null)) {
                $issues[] = $this->issue('pending_decision_has_provenance', 'blocked', $this->applicationRelated($application));
            } elseif (in_array($decision, ['accepted', 'rejected'], true)) {
                if ($application->decision_date === null || $application->decided_by_user_id === null) {
                    $issues[] = $this->issue('final_decision_missing_provenance', 'blocked', $this->applicationRelated($application));
                } elseif (! $decisionUsers->has((int) $application->decided_by_user_id)) {
                    $issues[] = $this->issue('decision_actor_missing', 'blocked', $this->applicationRelated($application));
                }
            }

            if ($students->count() > 1) {
                foreach ($students as $candidate) {
                    $issues[] = $this->issue('application_student_ambiguous', 'blocked', [
                        ...$this->applicationRelated($application),
                        'related_student_id' => (int) $candidate->student_id,
                    ]);
                }
            } elseif ($students->count() === 1) {
                $student = $students->sole();
            }

            if ($decision === 'accepted' && $student === null && $students->count() <= 1) {
                $issues[] = $this->issue('accepted_without_student', 'blocked', $this->applicationRelated($application));
            }
            if ($student !== null && $decision === 'pending') {
                $issues[] = $this->issue('student_with_pending_application', 'blocked', $this->studentRelated($application, $student));
            }
            if ($student !== null && $decision === 'rejected') {
                $issues[] = $this->issue('student_with_rejected_application', 'blocked', $this->studentRelated($application, $student));
            }
        }

        if ($student !== null) {
            if ($student->deleted_at !== null) {
                $issues[] = $this->issue('student_soft_deleted', 'blocked', $this->studentRelated($application, $student));
            }
            if ($application !== null && (int) $student->academic_program_id !== (int) $application->academic_program_id) {
                $issues[] = $this->issue('student_program_mismatch', 'blocked', $this->studentRelated($application, $student));
            }
            $level = $levels->get((int) $student->current_academic_level_id);
            $status = $statuses->get((int) $student->student_status_id);
            if ($level === null) {
                $issues[] = $this->issue('student_academic_level_missing', 'blocked', $this->studentRelated($application, $student));
            } elseif (! $level->is_active) {
                $issues[] = $this->issue('student_academic_level_inactive', 'warning', $this->studentRelated($application, $student));
            }
            if ($status === null) {
                $issues[] = $this->issue('student_status_missing', 'blocked', $this->studentRelated($application, $student));
            } elseif (! $status->is_active) {
                $issues[] = $this->issue('student_status_inactive', 'warning', $this->studentRelated($application, $student));
            }
        }

        $candidateState = $this->candidateState($processing, $record, $applicant, $application, $decision, $students, $student, $issues);
        $terminalCoherent = in_array($candidateState, self::TERMINAL_STATES, true)
            && ! $this->hasBlockingIssue($issues);

        if ($record->matched_academic_program_id !== null && $programReferenceComplete && ! $programActive) {
            $issues[] = $this->issue(
                $terminalCoherent ? 'historical_program_hierarchy_inactive' : 'program_hierarchy_inactive',
                $terminalCoherent ? 'warning' : 'blocked',
            );
        }

        if ($candidateState === 'inconsistent' && ! $this->hasIssue($issues, 'ministry_state_chain_mismatch')) {
            $issues[] = $this->issue('ministry_state_chain_mismatch', 'blocked');
        }

        return [
            'placement_record_id' => (int) $record->placement_record_id,
            'batch_id' => (int) $record->batch_id,
            'academic_year_id' => (int) $batch->academic_year_id,
            'row_number' => $record->row_number === null ? null : (int) $record->row_number,
            'processing_status' => $record->processing_status,
            'matched_academic_program_id' => $record->matched_academic_program_id === null ? null : (int) $record->matched_academic_program_id,
            'identity_key' => MinistryPlacementNormalizer::duplicateKey($record->national_civil_id),
            'base_pipeline_state' => $candidateState,
            'terminal_coherent' => $terminalCoherent,
            'issues' => $issues,
            'applicant' => $applicant === null ? null : [
                'applicant_id' => (int) $applicant->applicant_id,
                'applicant_number' => $applicant->applicant_number,
            ],
            'admission_application' => $application === null ? null : [
                'admission_application_id' => (int) $application->admission_application_id,
            ],
            'student' => $student === null ? null : [
                'student_id' => (int) $student->student_id,
                'student_number' => $student->student_number,
            ],
        ];
    }

    private function candidateState(string $processing, MinistryPlacementRecord $record, ?Applicant $applicant, ?AdmissionApplication $application, ?string $decision, Collection $students, ?Student $student, array &$issues): string
    {
        $noDownstream = $record->applicant_id === null && $applicant === null && $application === null && $students->isEmpty();
        if ($processing === 'imported' && $record->matched_academic_program_id === null && $noDownstream) {
            return 'imported';
        }
        if ($processing === 'program_matched' && $record->matched_academic_program_id !== null && $noDownstream) {
            return 'matched';
        }
        if (in_array($processing, ['applicant_created', 'documents_pending'], true)
            && $applicant !== null && $application !== null && $decision === 'pending' && $students->isEmpty()
            && ! $this->hasBlockingIssue($issues)) {
            return $processing === 'documents_pending' ? 'documents_pending' : 'applicant_pending';
        }
        if ($applicant !== null && $application !== null && $decision === 'rejected' && $students->isEmpty()
            && ! $this->hasBlockingIssue($issues)
            && in_array($processing, ['rejected', 'applicant_created', 'documents_pending'], true)) {
            if ($processing !== 'rejected') {
                $issues[] = $this->issue('ministry_state_chain_mismatch', 'warning');
            }

            return 'rejected';
        }
        if ($processing === 'enrolled' && $applicant !== null && $application !== null && $decision === 'accepted'
            && $students->count() === 1 && $student !== null && ! $this->hasBlockingIssue($issues)) {
            return 'enrolled';
        }

        return 'inconsistent';
    }

    /** @param Collection<int, array<string, mixed>> $items @param Collection<string, Collection<int, MinistryPlacementRecord>> $identityGroups */
    private function applyIdentityIssues(Collection $items, Collection $identityGroups): Collection
    {
        $items = $items->map(function (array $item, $recordId) use ($items, $identityGroups): array {
            $identityKey = $item['identity_key'];
            if ($identityKey === null) {
                $item['issues'][] = $this->issue(
                    $item['terminal_coherent'] ? 'identity_missing_terminal_record' : 'identity_missing',
                    $item['terminal_coherent'] ? 'warning' : 'blocked',
                );
                return $item;
            }

            $group = $identityGroups->get($identityKey, collect());
            if ($group->count() <= 1) {
                return $item;
            }
            $terminalCount = $group->filter(fn (MinistryPlacementRecord $record): bool => (bool) ($items->get((int) $record->placement_record_id)['terminal_coherent'] ?? false))->count();
            $related = $group->reject(fn (MinistryPlacementRecord $record): bool => (int) $record->placement_record_id === (int) $recordId)
                ->sortBy('placement_record_id');

            if ($item['terminal_coherent'] && $terminalCount >= 2) {
                $code = 'identity_conflict_multiple_terminal_records';
                $severity = 'blocked';
            } elseif ($item['terminal_coherent']) {
                $code = 'identity_conflict_terminal_record';
                $severity = 'warning';
            } else {
                $code = 'identity_conflict';
                $severity = 'blocked';
            }
            foreach ($related as $relatedRecord) {
                $item['issues'][] = $this->issue($code, $severity, [
                    'related_record_id' => (int) $relatedRecord->placement_record_id,
                    'related_batch_id' => (int) $relatedRecord->batch_id,
                ]);
            }

            return $item;
        });

        return $items->map(function (array $item): array {
            $issues = collect($item['issues'])->sortBy(function (array $issue): string {
                $position = array_search($issue['code'], self::ISSUE_ORDER, true);

                return sprintf(
                    '%04d:%010d:%010d:%010d:%010d:%010d',
                    $position === false ? count(self::ISSUE_ORDER) : $position,
                    $issue['related_batch_id'] ?? 0,
                    $issue['related_record_id'] ?? 0,
                    $issue['related_applicant_id'] ?? 0,
                    $issue['related_application_id'] ?? 0,
                    $issue['related_student_id'] ?? 0,
                );
            })->values();
            $severity = $issues->contains('severity', 'blocked') ? 'blocked' : ($issues->contains('severity', 'warning') ? 'warning' : 'clean');
            $pipelineState = $severity === 'blocked' ? 'inconsistent' : $item['base_pipeline_state'];

            unset($item['identity_key'], $item['base_pipeline_state'], $item['terminal_coherent']);
            $item['pipeline_state'] = $pipelineState;
            $item['reconciliation_severity'] = $severity;
            $item['issues'] = $issues->all();

            return $item;
        });
    }

    /** @param array{batches: Collection, items: Collection} $analysis @param array<string, mixed> $filters @return array<string, mixed> */
    private function payload(array $analysis, array $filters): array
    {
        $items = $analysis['items']->sortBy(fn (array $item): string => sprintf('%010d:%010d', $item['batch_id'], $item['placement_record_id']))->values();
        $filtered = $items->filter(function (array $item) use ($filters): bool {
            if (isset($filters['severity']) && $item['reconciliation_severity'] !== $filters['severity']) return false;
            if (isset($filters['pipeline_state']) && $item['pipeline_state'] !== $filters['pipeline_state']) return false;
            if (isset($filters['issue_code']) && ! collect($item['issues'])->contains('code', $filters['issue_code'])) return false;

            return true;
        })->values();
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 50);
        $lastPage = max(1, (int) ceil($filtered->count() / $perPage));
        $records = $filtered->slice(($page - 1) * $perPage, $perPage)->values()->all();

        return [
            'metrics' => $this->metrics($items),
            'checksum' => $this->checksum($items),
            'production_gate' => $items->contains('reconciliation_severity', 'blocked') ? 'BLOCKED' : 'READY',
            'records' => $records,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $filtered->count(),
                'last_page' => $lastPage,
            ],
        ];
    }

    /** @return array<string, int> */
    private function metrics(Collection $items): array
    {
        $metrics = ['total_records' => $items->count()];
        foreach (['clean', 'warning', 'blocked'] as $severity) {
            $metrics[$severity.'_records'] = $items->where('reconciliation_severity', $severity)->count();
        }
        foreach (['imported', 'matched', 'applicant_pending', 'documents_pending', 'enrolled', 'rejected', 'inconsistent'] as $state) {
            $metrics[$state.'_records'] = $items->where('pipeline_state', $state)->count();
        }
        $metrics['identity_conflict_records'] = $items->filter(fn (array $item): bool => collect($item['issues'])->contains(fn (array $issue): bool => str_starts_with($issue['code'], 'identity_conflict')))->count();

        return $metrics;
    }

    private function checksum(Collection $items): string
    {
        $canonical = $items->map(fn (array $item): string => implode(':', [
            $item['placement_record_id'],
            $item['batch_id'],
            $item['pipeline_state'],
            $item['reconciliation_severity'],
            $this->issueChecksumMaterial($item['issues']),
            $item['applicant']['applicant_id'] ?? '',
            $item['admission_application']['admission_application_id'] ?? '',
            $item['student']['student_id'] ?? '',
            $item['matched_academic_program_id'] ?? '',
            $item['academic_year_id'],
        ]))->implode("\n");

        return hash('sha256', $canonical);
    }

    private function issueChecksumMaterial(array $issues): string
    {
        $relationshipKeys = [
            'related_batch_id',
            'related_record_id',
            'related_applicant_id',
            'related_application_id',
            'related_student_id',
        ];

        return collect($issues)->map(function (array $issue) use ($relationshipKeys): string {
            $material = [$issue['code']];
            foreach ($relationshipKeys as $key) {
                if (array_key_exists($key, $issue)) {
                    $material[] = $key.'='.(int) $issue[$key];
                }
            }

            return implode(':', $material);
        })->unique()->sort()->implode(',');
    }

    /** @return array<string, int> */
    private function auditCoverage(): array
    {
        $counts = UserActivityLog::query()
            ->selectRaw('action_code, COUNT(*) AS action_count')
            ->where('module_code', 'admissions')
            ->whereIn('action_code', self::AUDIT_ACTIONS)
            ->groupBy('action_code')
            ->pluck('action_count', 'action_code');

        return collect(self::AUDIT_ACTIONS)->mapWithKeys(fn (string $action): array => [$action => (int) ($counts[$action] ?? 0)])->all();
    }

    /** @param array<string, int> $related @return array<string, mixed> */
    private function issue(string $code, string $severity, array $related = []): array
    {
        return ['code' => $code, 'severity' => $severity, 'message' => self::ISSUE_MESSAGES[$code], ...$related];
    }

    private function hasBlockingIssue(array $issues): bool
    {
        return collect($issues)->contains('severity', 'blocked');
    }

    private function hasIssue(array $issues, string $code): bool
    {
        return collect($issues)->contains('code', $code);
    }

    private function expectedApplicantNumber(MinistryPlacementRecord $record): string
    {
        return 'MP-R'.(int) $record->placement_record_id;
    }

    private function applicationContextKey(int $applicantId, int $programId, int $academicYearId): string
    {
        return $applicantId.':'.$programId.':'.$academicYearId;
    }

    /** @return array<string, int> */
    private function applicationRelated(AdmissionApplication $application): array
    {
        return [
            'related_applicant_id' => (int) $application->applicant_id,
            'related_application_id' => (int) $application->admission_application_id,
        ];
    }

    /** @return array<string, int> */
    private function studentRelated(?AdmissionApplication $application, Student $student): array
    {
        return [
            'related_applicant_id' => $application === null ? 0 : (int) $application->applicant_id,
            'related_application_id' => $application === null ? 0 : (int) $application->admission_application_id,
            'related_student_id' => (int) $student->student_id,
        ];
    }
}
