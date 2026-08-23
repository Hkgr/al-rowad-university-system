<?php

namespace App\Services;

use App\Exceptions\GradeException;
use App\Models\ApprovalStatus;
use App\Models\CourseOffering;
use App\Models\GradeApproval;
use App\Models\GradeAuditLog;
use App\Models\GradeComponent;
use App\Models\GradePartApproval;
use App\Models\GradePartApprovalEvent;
use App\Models\ResultStatus;
use App\Models\StudentCourseRegistration;
use App\Models\StudentCourseResult;
use App\Models\StudentGradeComponent;
use App\Models\User;
use App\Support\CourseRequirementClassification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GradePartWorkflowService
{
    public function __construct(
        private readonly DataScopeService $dataScope,
        private readonly GradeService $grades,
        private readonly ProfessorGradeAssignmentService $assignments,
        private readonly SupplementaryExamEligibilityService $supplementaryEligibility,
    ) {}

    public function workflow(int $offeringId, ?User $actor = null): array
    {
        $offering = CourseOffering::query()
            ->with([
                'course',
                'academicYear',
                'semester',
                'gradeApprovals.approvalStatus',
                'gradeComponents' => fn ($q) => $q->where('is_required', true),
            ])
            ->findOrFail($offeringId);
        $required = $this->requiredParts($offeringId);
        $approvals = GradePartApproval::query()
            ->with(['submittedBy.employee', 'reviewedBy.employee'])
            ->where('course_offering_id', $offeringId)
            ->get()
            ->keyBy('component_type');
        $registrations = StudentCourseRegistration::query()->where('course_offering_id', $offeringId)->current()
            ->with(['student', 'registrationStatus', 'resultStatus', 'studentCourseResult.resultStatus', 'studentGradeComponents.gradeComponent'])->get();

        $isExamActor = $actor !== null && $actor->hasPermission('exams.manage');
        $assignment = $actor === null
            ? ['assigned_parts' => [], 'assignment_mode' => null, 'part_assignments' => []]
            : $this->assignments->describeAssignment($actor, $offering);
        $assignedParts = $assignment['assigned_parts'];
        $finalization = $this->finalization($offering, $required, $approvals);
        $official = $finalization['official_result_available'] === true;

        $parts = [];
        foreach (GradePartApproval::PARTS as $part) {
            $approval = $approvals->get($part);
            $status = $approval?->status ?? 'draft';
            $requiredPart = in_array($part, $required, true);
            $assignedToMe = in_array($part, $assignedParts, true);
            $editableStatus = in_array($status, ['draft', 'returned'], true);
            $complete = $this->partComplete($registrations, $offering->gradeComponents, $part);
            $gradeStateAllowsEditing = $registrations->contains(
                fn ($registration): bool => $registration->allowsGradeEntry() && ! $this->registrationIsDeprived($registration)
            );
            $parts[$part] = [
                'required' => $requiredPart,
                'assigned_to_me' => $assignedToMe,
                'status' => $status,
                'approval_id' => $approval?->grade_part_approval_id,
                'can_edit' => $requiredPart && $assignedToMe && $editableStatus && $gradeStateAllowsEditing && ! $official,
                'can_submit' => $assignedToMe && $requiredPart && $editableStatus && $complete && ! $official,
                'submission_version' => $approval?->submission_version ?? 0,
                'submitted_at' => $approval?->submitted_at?->toISOString(),
                'submitted_by_user_id' => $approval?->submitted_by_user_id,
                'submitted_by_name' => $this->userName($approval?->submittedBy),
                'reviewed_at' => $approval?->reviewed_at?->toISOString(),
                'review_notes' => $approval?->review_notes,
            ];
        }

        return [
            'course_offering_id' => $offeringId,
            'course' => [
                'course_id' => $offering->course_id,
                'course_code' => $offering->course?->course_code,
                'course_name' => $offering->course?->course_name,
                'requirement_classification' => CourseRequirementClassification::forOffering($offering),
            ],
            'requirement_classification' => CourseRequirementClassification::forOffering($offering),
            'academic_year' => $offering->academicYear === null ? null : [
                'academic_year_id' => $offering->academicYear->academic_year_id,
                'year_name' => $offering->academicYear->year_name,
            ],
            'semester' => $offering->semester === null ? null : [
                'semester_id' => $offering->semester->semester_id,
                'semester_name' => $offering->semester->semester_name,
            ],
            'assigned_parts' => $assignedParts,
            'assignment_mode' => $assignment['assignment_mode'],
            'part_assignments' => $assignment['part_assignments'],
            'required_parts' => $required,
            'parts' => $parts,
            'finalization' => $finalization,
            'components' => collect(GradePartApproval::PARTS)->mapWithKeys(fn ($part) => [$part => $offering->gradeComponents
                ->where('component_type', $part)
                ->map(fn ($component) => [
                    'grade_component_id' => $component->grade_component_id,
                    'component_type' => $component->component_type,
                    'max_mark' => (float) $component->max_mark,
                ])->values()->all()])->all(),
            'students' => $registrations->map(function ($registration) use ($parts, $isExamActor, $official) {
                $marks = collect(GradePartApproval::PARTS)->mapWithKeys(function ($part) use ($registration, $parts, $isExamActor) {
                    $expose = $isExamActor || ($parts[$part]['assigned_to_me'] ?? false);
                    if (! $expose) {
                        return [$part => []];
                    }

                    return [$part => $registration->studentGradeComponents
                        ->filter(fn ($grade) => $grade->gradeComponent?->component_type === $part)
                        ->map(fn ($grade) => [
                            'grade_component_id' => $grade->grade_component_id,
                            'mark' => $grade->mark === null ? null : (float) $grade->mark,
                            'max_mark' => (float) $grade->gradeComponent->max_mark,
                        ])->values()->all()];
                })->all();

                $result = $registration->studentCourseResult;
                $deferral = $this->supplementaryEligibility->activeValidDeferral($registration);

                return [
                    'registration_id' => $registration->student_course_registration_id,
                    'registration_status' => $registration->registrationStatus?->status_code,
                    'theoretical_deferred' => $deferral !== null,
                    'supplementary_exam_offering_id' => $deferral?->supplementary_exam_offering_id,
                    'supplementary_exam_period_id' => $deferral?->offering?->supplementary_exam_period_id,
                    'deferral_id' => $deferral?->getKey(),
                    'is_deprived' => $this->registrationIsDeprived($registration),
                    'student' => [
                        'student_id' => $registration->student_id,
                        'student_number' => $registration->student?->student_number,
                        'first_name' => $registration->student?->first_name,
                        'last_name' => $registration->student?->last_name,
                    ],
                    'marks' => $marks,
                    'official_result' => $official && $result !== null ? [
                        'theoretical_total' => $result->theoretical_total === null ? null : (float) $result->theoretical_total,
                        'practical_total' => $result->practical_total === null ? null : (float) $result->practical_total,
                        'final_mark' => $result->final_mark === null ? null : (float) $result->final_mark,
                        'is_deprived' => (bool) $result->is_deprived,
                        'result_status' => $result->resultStatus === null ? null : [
                            'status_code' => $result->resultStatus->status_code,
                            'status_name' => $result->resultStatus->status_name,
                        ],
                    ] : null,
                ];
            })->values()->all(),
        ];
    }

    public function savePart(StudentCourseRegistration $registration, string $part, array $data, User $user): array
    {
        $this->assertPart($part);
        $this->assignments->assertCanManageGradePart($user, (int) $registration->course_offering_id, $part);
        return DB::transaction(function () use ($registration, $part, $data, $user): array {
            $locked = StudentCourseRegistration::query()->whereKey($registration->student_course_registration_id)
                ->with(['registrationStatus', 'resultStatus', 'studentCourseResult.resultStatus'])
                ->lockForUpdate()->firstOrFail();
            $this->grades->assertNotSupplementaryMaterialized((int) $locked->getKey());
            if (! $locked->allowsGradeEntry()) {
                $this->fail('Grade entry is not allowed for this registration.', 'grade_entry_not_allowed', 409);
            }
            if ($this->registrationIsDeprived($locked)) {
                $this->fail('Grades cannot be entered or changed for a deprived student.', 'deprived_student_grade_locked', 409);
            }
            CourseOfferingLock::lock((int) $locked->course_offering_id);
            $this->assertRequired((int) $locked->course_offering_id, $part);
            $approval = $this->lockApproval((int) $locked->course_offering_id, $part);
            if ($approval && ! in_array($approval->status, ['draft', 'returned'], true)) $this->fail('This grade part is locked.', 'grade_part_locked');
            $components = GradeComponent::query()->where('course_offering_id', $locked->course_offering_id)->where('component_type', $part)->where('is_required', true)->lockForUpdate()->get()->keyBy('grade_component_id');
            StudentGradeComponent::query()->where('student_course_registration_id', $locked->getKey())->whereIn('grade_component_id', $components->keys())->lockForUpdate()->get();
            if ($part === 'theoretical' && $this->supplementaryEligibility->resolveInvalidCurrentDeferral($locked, (int) $user->user_id)) {
                $this->fail('The regular theoretical examination was deferred.', 'supplementary_theoretical_deferred');
            }
            $input = $data['components'] ?? null;
            if ($input === null) {
                if ($components->count() !== 1) $this->fail('Component marks are required when a part has multiple components.', 'invalid_grade_part');
                $input = [['grade_component_id' => $components->keys()->first(), 'mark' => $data['mark']]];
            }
            foreach ($input as $item) {
                $component = $components->get($item['grade_component_id']);
                if (! $component || ($item['mark'] !== null && (float) $item['mark'] > (float) $component->max_mark)) $this->fail('A mark is outside the grade component limits.', 'invalid_grade_part');
                $grade = StudentGradeComponent::query()->where('student_course_registration_id', $locked->student_course_registration_id)->where('grade_component_id', $component->grade_component_id)->lockForUpdate()->first();
                $old = $grade?->mark;
                $grade = StudentGradeComponent::query()->updateOrCreate(
                    ['student_course_registration_id' => $locked->student_course_registration_id, 'grade_component_id' => $component->grade_component_id],
                    ['mark' => $item['mark'], 'grade_status' => $approval?->status === 'returned' ? 'returned' : 'draft', 'entered_by_user_id' => $user->user_id, 'entered_at' => now(), 'notes' => $data['notes'] ?? null]
                );
                GradeAuditLog::query()->create(['student_grade_component_id' => $grade->student_grade_component_id, 'old_mark' => $old, 'new_mark' => $item['mark'], 'changed_by_user_id' => $user->user_id, 'change_reason' => 'grade_part_saved:'.$part, 'changed_at' => now()]);
            }
            return $this->workflow((int) $locked->course_offering_id, $user);
        });
    }

    public function submit(int $offeringId, string $part, User $user): GradePartApproval
    {
        $this->assertPart($part);
        $this->assignments->assertCanManageGradePart($user, $offeringId, $part);
        return DB::transaction(function () use ($offeringId, $part, $user): GradePartApproval {
            CourseOffering::query()->whereKey($offeringId)->lockForUpdate()->firstOrFail();

            return $this->submitPartInTransaction($offeringId, $part, $user->user_id);
        });
    }

    public function submitMyParts(User $user, int $offeringId): array
    {
        if (! $user->hasPermission('grades.manage')) {
            $this->fail('Grade management permission is required.', 'unauthorized_grade_part', 403);
        }

        $assigned = $this->assignments->assignedGradeParts($user, $offeringId);
        if ($assigned === []) {
            $this->fail('You are not authorized to submit grade parts for this offering.', 'unauthorized_grade_part', 403);
        }

        return DB::transaction(function () use ($user, $offeringId, $assigned): array {
            CourseOffering::query()->whereKey($offeringId)->lockForUpdate()->firstOrFail();
            $required = $this->requiredParts($offeringId);
            $assignedRequired = array_values(array_intersect($assigned, $required));
            if ($assignedRequired === []) {
                $this->fail('None of your assigned grade parts are required for this offering.', 'grade_part_not_required');
            }

            $approvals = GradePartApproval::query()
                ->where('course_offering_id', $offeringId)
                ->whereIn('component_type', $assignedRequired)
                ->lockForUpdate()
                ->get()
                ->keyBy('component_type');

            $editable = [];
            $unchanged = [];
            foreach ($assignedRequired as $part) {
                $status = $approvals->get($part)?->status ?? 'draft';
                if (in_array($status, ['submitted', 'approved'], true)) {
                    $unchanged[] = $part;
                    continue;
                }
                if (! in_array($status, ['draft', 'returned'], true)) {
                    $this->fail('This grade part cannot be submitted.', 'grade_part_already_submitted');
                }
                $editable[] = $part;
            }

            $ownsBothRequired = in_array('theoretical', $assignedRequired, true)
                && in_array('practical', $assignedRequired, true);
            if ($ownsBothRequired && count($editable) === 2) {
                $this->assertPartsComplete($offeringId, $editable);
                foreach ($editable as $part) {
                    $this->submitPartInTransaction($offeringId, $part, $user->user_id);
                }
                $submitted = $editable;
            } else {
                $this->assertPartsComplete($offeringId, $editable);
                $submitted = [];
                foreach ($editable as $part) {
                    $this->submitPartInTransaction($offeringId, $part, $user->user_id);
                    $submitted[] = $part;
                }
            }

            $workflow = $this->workflow($offeringId, $user);

            return [
                'submitted_parts' => $submitted,
                'unchanged_parts' => $unchanged,
                'parts' => $workflow['parts'],
                'workflow' => $workflow,
                'all_required_submitted' => $workflow['finalization']['all_required_submitted'],
                'all_required_approved' => $workflow['finalization']['all_required_approved'],
                'official_result_available' => $workflow['finalization']['official_result_available'],
            ];
        });
    }

    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        return $this->dataScope->scopeResourceQuery(GradePartApproval::query(), $user)
            ->with([
                'courseOffering.course',
                'courseOffering.academicYear',
                'courseOffering.semester',
                'courseOffering.gradePartApprovals',
                'courseOffering.gradeComponents',
                'submittedBy.employee',
                'reviewedBy.employee',
            ])
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['component_type'] ?? null, fn (Builder $q, $v) => $q->where('component_type', $v))
            ->orderByRaw("CASE WHEN status = 'submitted' THEN 0 ELSE 1 END")->orderBy('submitted_at')->paginate($filters['per_page'] ?? 15);
    }

    public function find(User $user, int $id): GradePartApproval
    {
        $approval = $this->dataScope->scopeResourceQuery(GradePartApproval::query(), $user)
            ->with([
                'courseOffering.course',
                'courseOffering.academicYear',
                'courseOffering.semester',
                'courseOffering.gradePartApprovals',
                'courseOffering.gradeComponents',
                'submittedBy.employee',
                'reviewedBy.employee',
                'events',
            ])->find($id);
        if (! $approval && GradePartApproval::query()->whereKey($id)->exists()) $this->fail('You are not authorized to access this grade part.', 'unauthorized_grade_part', 403);
        return $approval ?? GradePartApproval::query()->findOrFail($id);
    }

    public function review(User $user, int $id, string $action, ?string $notes): GradePartApproval
    {
        $visible = $this->find($user, $id);
        return DB::transaction(function () use ($visible, $user, $action, $notes): GradePartApproval {
            CourseOffering::query()->whereKey($visible->course_offering_id)->lockForUpdate()->firstOrFail();
            $approval = GradePartApproval::query()->whereKey($visible->grade_part_approval_id)->lockForUpdate()->firstOrFail();
            if ($approval->status === 'approved' && $action === 'approve') return $approval;
            if ($approval->status !== 'submitted') $this->fail('Only a submitted grade part may be reviewed.', 'grade_part_not_submitted');
            if ($action === 'return' && trim((string) $notes) === '') $this->fail('Review notes are required.', 'missing_review_notes');
            $registrations = StudentCourseRegistration::query()
                ->where('course_offering_id', $approval->course_offering_id)
                ->current()
                ->orderBy('student_course_registration_id')
                ->lockForUpdate()
                ->get();
            foreach ($registrations as $registration) {
                $this->grades->assertNotSupplementaryMaterialized((int) $registration->getKey());
            }
            $old = $approval->toArray();
            $approval->update(['status' => $action === 'approve' ? 'approved' : 'returned', 'reviewed_by_user_id' => $user->user_id, 'reviewed_at' => now(), 'review_notes' => $notes]);
            $this->setComponentStatus($approval, $action === 'approve' ? 'approved' : 'returned');
            $this->event($approval, $action === 'approve' ? 'approved' : 'returned', $old, $approval->fresh()->toArray(), $user->user_id);
            if ($action === 'approve' && $this->allRequiredApproved((int) $approval->course_offering_id)) $this->finalize((int) $approval->course_offering_id, $user->user_id);
            return $approval->fresh(['courseOffering.course', 'courseOffering.academicYear', 'courseOffering.semester', 'courseOffering.gradePartApprovals', 'courseOffering.gradeComponents', 'events']);
        });
    }

    private function submitPartInTransaction(int $offeringId, string $part, int $userId): GradePartApproval
    {
        $this->assertRequired($offeringId, $part);
        $approval = $this->lockApproval($offeringId, $part);
        if ($approval?->status === 'approved') $this->fail('This grade part is already approved.', 'grade_part_already_approved');
        if ($approval?->status === 'submitted') return $approval;
        if ($approval && ! in_array($approval->status, ['draft', 'returned'], true)) $this->fail('This grade part cannot be submitted.', 'grade_part_already_submitted');
        $registrations = StudentCourseRegistration::query()->where('course_offering_id', $offeringId)->current()->with(['studentCourseResult.resultStatus', 'resultStatus'])->lockForUpdate()->get();
        foreach ($registrations as $registration) {
            $this->grades->assertNotSupplementaryMaterialized((int) $registration->getKey());
        }
        $components = GradeComponent::query()->where('course_offering_id', $offeringId)->where('component_type', $part)->where('is_required', true)->get();
        if ($registrations->isEmpty() || ! $this->partComplete($registrations, $components, $part)) $this->fail('Required marks for this grade part are incomplete.', 'grade_part_incomplete');
        $old = $approval?->toArray();
        $values = ['status' => 'submitted', 'submission_version' => ($approval?->submission_version ?? 0) + 1, 'submitted_by_user_id' => $userId, 'submitted_at' => now(), 'reviewed_by_user_id' => null, 'reviewed_at' => null, 'review_notes' => null];
        $approval ? $approval->update($values) : $approval = GradePartApproval::query()->create(['course_offering_id' => $offeringId, 'component_type' => $part] + $values);
        StudentGradeComponent::query()->whereIn('student_course_registration_id', $registrations->pluck('student_course_registration_id'))->whereIn('grade_component_id', $components->pluck('grade_component_id'))->update(['grade_status' => 'submitted']);
        $this->event($approval, 'submitted', $old, $approval->fresh()->toArray(), $userId);
        return $approval->fresh();
    }

    /**
     * @param  list<string>  $submitting
     */
    /**
     * @param  list<string>  $parts
     */
    private function assertPartsComplete(int $offeringId, array $parts): void
    {
        if ($parts === []) {
            return;
        }

        $registrations = StudentCourseRegistration::query()
            ->where('course_offering_id', $offeringId)
            ->current()
            ->with(['studentCourseResult.resultStatus', 'resultStatus'])
            ->get();
        $components = GradeComponent::query()
            ->where('course_offering_id', $offeringId)
            ->whereIn('component_type', $parts)
            ->where('is_required', true)
            ->get();

        foreach ($parts as $part) {
            if ($registrations->isEmpty() || ! $this->partComplete($registrations, $components, $part)) {
                $this->fail('Required marks for this grade part are incomplete.', 'grade_part_incomplete');
            }
        }
    }

    /**
     * @param  Collection<string, GradePartApproval>  $approvals
     * @param  list<string>  $required
     * @return array{
     *     required_parts: list<string>,
     *     submitted_parts: list<string>,
     *     approved_parts: list<string>,
     *     all_required_submitted: bool,
     *     all_required_approved: bool,
     *     official_result_available: bool,
     *     finalized_at: string|null
     * }
     */
    private function finalization(CourseOffering $offering, array $required, Collection $approvals): array
    {
        $submitted = [];
        $approved = [];
        foreach ($required as $part) {
            $status = $approvals->get($part)?->status ?? 'draft';
            if (in_array($status, ['submitted', 'approved'], true)) {
                $submitted[] = $part;
            }
            if ($status === 'approved') {
                $approved[] = $part;
            }
        }

        $allRequiredApproved = $required !== [] && count($approved) === count($required);
        $allRequiredSubmitted = $required !== [] && count($submitted) === count($required);
        $official = $allRequiredApproved && $this->grades->isOfficiallyApprovedOffering($offering);
        $latestApproval = $offering->gradeApprovals
            ->sortByDesc('grade_approval_id')
            ->first();

        return [
            'required_parts' => $required,
            'submitted_parts' => $submitted,
            'approved_parts' => $approved,
            'all_required_submitted' => $allRequiredSubmitted,
            'all_required_approved' => $allRequiredApproved,
            'official_result_available' => $official,
            'finalized_at' => $official ? ($latestApproval?->approval_date?->toISOString() ?? $latestApproval?->updated_at?->toISOString()) : null,
        ];
    }

    private function finalize(int $offeringId, int $userId): void
    {
        $required = $this->requiredParts($offeringId);
        $registrations = StudentCourseRegistration::query()->where('course_offering_id', $offeringId)->current()->with('resultStatus')->lockForUpdate()->get();
        foreach ($registrations as $registration) {
            $this->grades->assertNotSupplementaryMaterialized((int) $registration->getKey());
        }
        $components = GradeComponent::query()->where('course_offering_id', $offeringId)->where('is_required', true)->get();
        $this->grades->assertRequiredPartsPolicyCompatible(
            in_array('theoretical', $required, true), in_array('practical', $required, true),
            (float) $components->where('component_type', 'theoretical')->sum('max_mark'),
            (float) $components->where('component_type', 'practical')->sum('max_mark')
        );
        foreach ($registrations as $registration) {
            // An explicit, currently valid deferral is provenance for an intentionally absent regular theory result.
            if ($this->supplementaryEligibility->resolveInvalidCurrentDeferral($registration, $userId)) continue;
            $existing = StudentCourseResult::query()->where('student_course_registration_id', $registration->student_course_registration_id)->lockForUpdate()->first();
            $existingStatus = $existing === null ? $registration->resultStatus?->status_code
                : ResultStatus::query()->whereKey($existing->result_status_id)->lockForUpdate()->value('status_code');
            $isDeprived = (bool) $existing?->is_deprived || $existingStatus === 'deprived'
                || $registration->resultStatus?->status_code === 'deprived';
            $marks = StudentGradeComponent::query()->where('student_course_registration_id', $registration->student_course_registration_id)
                ->whereIn('grade_component_id', $components->pluck('grade_component_id'))->lockForUpdate()->get()->keyBy('grade_component_id');
            $totals = ['theoretical' => null, 'practical' => null];
            if (! $isDeprived) {
                foreach (GradePartApproval::PARTS as $part) {
                    $partComponents = $components->where('component_type', $part);
                    if (in_array($part, $required, true)) {
                        foreach ($partComponents as $component) {
                            $grade = $marks->get($component->grade_component_id);
                            if ($grade === null || $grade->mark === null) $this->fail('Required grade-part marks are incomplete.', 'grade_part_incomplete');
                        }
                        $totals[$part] = $partComponents->sum(fn ($component) => (float) $marks->get($component->grade_component_id)->mark);
                    }
                }
            }
            $calculation = $isDeprived
                ? ['result_status_code' => 'deprived', 'final_mark' => $existing?->final_mark ?? 0]
                : $this->grades->buildCalculationForRequiredParts(
                    $totals['theoretical'], $totals['practical'], in_array('theoretical', $required, true),
                    in_array('practical', $required, true),
                    (float) $components->where('component_type', 'theoretical')->sum('max_mark'),
                    (float) $components->where('component_type', 'practical')->sum('max_mark'),
                    $existingStatus, false
                );
            $statusId = ResultStatus::query()->where('status_code', $calculation['result_status_code'])->where('is_active', true)
                ->lockForUpdate()->value('result_status_id');
            if ($statusId === null) $this->fail('The required result status is not configured.', 'result_status_missing');

            StudentCourseResult::query()->updateOrCreate(
                ['student_course_registration_id' => $registration->student_course_registration_id],
                ['theoretical_total' => $isDeprived ? ($existing?->theoretical_total ?? 0) : ($totals['theoretical'] ?? 0),
                 'practical_total' => $isDeprived ? ($existing?->practical_total ?? 0) : ($totals['practical'] ?? 0),
                 'coursework_total' => $existing?->coursework_total ?? 0, 'final_mark' => $calculation['final_mark'],
                 'result_status_id' => $statusId, 'is_deprived' => $isDeprived,
                 'calculated_at' => now(), 'calculated_by_user_id' => $userId]
            );
            $registration->update(['result_status_id' => $statusId]);
        }
        $approvedStatus = ApprovalStatus::query()->where('status_code', 'approved')->where('is_active', true)->lockForUpdate()->value('approval_status_id');
        if ($approvedStatus === null) $this->fail('The approved grade status is not configured.', 'grade_approval_status_missing');
        $submitter = GradePartApproval::query()->where('course_offering_id', $offeringId)->whereIn('component_type', $required)->orderByDesc('submitted_at')->first();
        GradeApproval::query()->updateOrCreate(['course_offering_id' => $offeringId], ['approval_status_id' => $approvedStatus, 'submitted_by_user_id' => $submitter?->submitted_by_user_id ?? $userId, 'submitted_at' => $submitter?->submitted_at ?? now(), 'approved_by_user_id' => $userId, 'approval_role' => 'examination_committee', 'approval_date' => now(), 'approval_notes' => 'Finalized after all required grade parts were approved.']);
    }

    private function requiredParts(int $offeringId): array { return GradeComponent::query()->where('course_offering_id', $offeringId)->where('is_required', true)->whereIn('component_type', GradePartApproval::PARTS)->distinct()->pluck('component_type')->values()->all(); }
    private function assertRequired(int $offeringId, string $part): void { if (! in_array($part, $this->requiredParts($offeringId), true)) $this->fail('This grade part is not required.', 'grade_part_not_required'); }
    private function assertPart(string $part): void { if (! in_array($part, GradePartApproval::PARTS, true)) $this->fail('The grade part must be practical or theoretical.', 'invalid_grade_part'); }
    private function lockApproval(int $offeringId, string $part): ?GradePartApproval { return GradePartApproval::query()->where('course_offering_id', $offeringId)->where('component_type', $part)->lockForUpdate()->first(); }
    private function allRequiredApproved(int $offeringId): bool { $required = $this->requiredParts($offeringId); return $required !== [] && GradePartApproval::query()->where('course_offering_id', $offeringId)->whereIn('component_type', $required)->where('status', 'approved')->count() === count($required); }
    private function partComplete($registrations, $components, string $part): bool
    {
        $partComponents = $components->where('component_type', $part); if ($partComponents->isEmpty()) return false;
        foreach ($registrations as $registration) {
            if ($registration->studentCourseResult?->is_deprived || $registration->studentCourseResult?->resultStatus?->status_code === 'deprived' || $registration->resultStatus?->status_code === 'deprived') continue;
            if ($part === 'theoretical' && $this->supplementaryEligibility->activeValidDeferral($registration)) continue;
            $grades = StudentGradeComponent::query()->where('student_course_registration_id', $registration->student_course_registration_id)->whereIn('grade_component_id', $partComponents->pluck('grade_component_id'))->get()->keyBy('grade_component_id');
            foreach ($partComponents as $component) { $grade = $grades->get($component->grade_component_id); if (! $grade || $grade->mark === null || (float) $grade->mark < 0 || (float) $grade->mark > (float) $component->max_mark) return false; }
        }
        return true;
    }
    private function registrationIsDeprived(StudentCourseRegistration $registration): bool
    {
        return (bool) $registration->studentCourseResult?->is_deprived
            || $registration->studentCourseResult?->resultStatus?->status_code === 'deprived'
            || $registration->resultStatus?->status_code === 'deprived';
    }
    private function setComponentStatus(GradePartApproval $approval, string $status): void { $componentIds = GradeComponent::query()->where('course_offering_id', $approval->course_offering_id)->where('component_type', $approval->component_type)->where('is_required', true)->lockForUpdate()->pluck('grade_component_id'); StudentGradeComponent::query()->whereIn('grade_component_id', $componentIds)->whereHas('studentCourseRegistration', fn ($q) => $q->where('course_offering_id', $approval->course_offering_id))->where('grade_status', 'submitted')->lockForUpdate()->update(['grade_status' => $status]); }
    private function event(GradePartApproval $approval, string $action, ?array $old, array $new, int $userId): void { GradePartApprovalEvent::query()->create(['grade_part_approval_id' => $approval->grade_part_approval_id, 'submission_version' => $approval->submission_version, 'action' => $action, 'old_values' => $old, 'new_values' => $new, 'performed_by_user_id' => $userId, 'performed_at' => now()]); }
    private function userName(?User $user): ?string
    {
        if ($user?->employee) {
            $name = trim((string) $user->employee->first_name.' '.(string) $user->employee->last_name);
            if ($name !== '') {
                return $name;
            }
        }

        return $user?->username;
    }
    private function fail(string $message, string $code, int $status = 409): never { throw new GradeException($message, status: $status, errorCode: $code); }
}
