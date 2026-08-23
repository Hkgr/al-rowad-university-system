<?php

namespace App\Services;

use App\Exceptions\GradeException;
use App\Models\CourseOffering;
use App\Models\FacultyMember;
use App\Models\GradeApproval;
use App\Models\StudentCourseRegistration;
use App\Models\StudentCourseResult;
use App\Models\SupplementaryExamGradeEvent;
use App\Models\SupplementaryExamGradeResult;
use App\Models\SupplementaryExamGradeSubmission;
use App\Models\SupplementaryExamGraderAssignment;
use App\Models\SupplementaryExamMaterialization;
use App\Models\SupplementaryExamOffering;
use App\Models\SupplementaryExamOfferingSource;
use App\Models\SupplementaryExamPeriod;
use App\Models\SupplementaryExamPeriodEvent;
use App\Models\SupplementaryExamRegistration;
use App\Models\User;
use App\Support\SupplementaryExamGradingGovernance as Governance;
use App\Support\SupplementaryExamMaterializationGovernance as MaterializationGovernance;
use App\Support\SupplementaryExamTargetGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lock order: period, offering, current assignment, registrations/results,
 * original registrations, then materialization provenance.
 */
class SupplementaryExamGradingService
{
    public function __construct(
        private readonly GradeService $grades,
        private readonly DataScopeService $scope,
    ) {}

    public function professorOfferings(User $actor)
    {
        $this->professor($actor, Governance::VIEW);
        $this->ready();
        $facultyId = $this->facultyId($actor);

        return SupplementaryExamOffering::query()
            ->with(['period', 'course', 'academicProgram'])
            ->whereHas('graderAssignments', fn ($query) => $query
                ->where('faculty_member_id', $facultyId)
                ->where('current_slot', 1))
            ->orderBy('supplementary_exam_offering_id')
            ->get();
    }

    public function roster(User $actor, SupplementaryExamOffering $offering): array
    {
        $this->professor($actor, Governance::VIEW);
        $this->ready();
        $this->assertAssigned($actor, $offering);

        return $this->rosterPayload($actor, $offering);
    }

    public function reviewQueue(User $actor): array
    {
        $this->exam($actor, Governance::REVIEW);
        $this->ready();
        $offerings = SupplementaryExamOffering::query()
            ->with(['period', 'course', 'academicProgram.department'])
            ->whereHas('period', fn ($query) => $query
                ->whereIn('status', Governance::REVIEW_QUEUE_PERIOD_STATUSES))
            ->orderBy('supplementary_exam_offering_id')
            ->get();
        $mutableProgramIds = $this->mutableProgramIds($actor, $offerings);
        $offerings = $offerings->filter(fn (SupplementaryExamOffering $offering): bool =>
            $mutableProgramIds->contains((int) $offering->academic_program_id)
        )->values();

        return $this->rosterPayloads($actor, $offerings, $mutableProgramIds);
    }

    /**
     * Bounded audit catalog under the same authority and mutation-safe scope as
     * the Exam Officer queue. Terminal periods stay selectable after their
     * offerings leave the active review queue.
     *
     * @return list<SupplementaryExamPeriod>
     */
    public function reviewPeriodCatalog(User $actor): array
    {
        $this->exam($actor, Governance::REVIEW);
        $this->ready();

        return SupplementaryExamPeriod::query()
            ->whereHas('supplementaryExamOfferings', fn ($offerings) => $offerings
                ->whereHas('academicProgram', fn ($programs) =>
                    $this->scope->scopeProgramsForMutation($programs, $actor)))
            ->orderByDesc('supplementary_exam_period_id')
            ->limit(100)
            ->get()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function graderOptions(
        User $actor,
        SupplementaryExamOffering $offering,
        ?string $search = null,
    ): array {
        $this->exam($actor, Governance::ASSIGN);
        $this->ready();
        $offering = SupplementaryExamOffering::query()->findOrFail($offering->getKey());
        if (! $this->scope->canMutateProgram($actor, (int) $offering->academic_program_id)) {
            $this->fail('The supplementary offering is outside the assigned data scope.', 'supplementary_grade_out_of_scope', 403);
        }

        $term = mb_substr(trim((string) $search), 0, 100);
        $query = $this->scope->scopeFacultyMembersForMutation(FacultyMember::query(), $actor)
            ->with('employee:employee_id,employee_number,first_name,last_name')
            ->where('is_active', true)
            ->whereHas('employee.users', fn ($users) => $users
                ->whereHas('accountStatus', fn ($status) => $status->where('status_code', 'active'))
                ->whereHas('userRoleRecords', fn ($roles) => $roles
                    ->where('is_active', true)
                    ->whereHas('role', fn ($role) => $role
                        ->where('role_code', 'doctor_instructor')
                        ->where('is_active', true)))
                ->whereHas('userRoleRecords', fn ($roles) => $roles
                    ->where('is_active', true)
                    ->whereHas('role', fn ($role) => $role
                        ->where('is_active', true)
                        ->whereHas('rolePermissions.permission', fn ($permission) => $permission
                            ->where('permission_code', Governance::VIEW)
                            ->where('is_active', true))))
                ->whereHas('userRoleRecords', fn ($roles) => $roles
                    ->where('is_active', true)
                    ->whereHas('role', fn ($role) => $role
                        ->where('is_active', true)
                        ->whereHas('rolePermissions.permission', fn ($permission) => $permission
                            ->where('permission_code', Governance::ENTER)
                            ->where('is_active', true)))));

        if ($term !== '') {
            $query->whereHas('employee', fn ($employee) => $employee->where(function ($match) use ($term): void {
                $match->where('employee_number', 'like', "%{$term}%")
                    ->orWhere('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%");
            }));
        }

        return $query->orderBy('faculty_member_id')->limit(50)->get()->map(function (FacultyMember $faculty): array {
            $employee = $faculty->employee;

            return [
                'faculty_member_id' => (int) $faculty->getKey(),
                'employee_id' => $employee?->employee_id === null ? null : (int) $employee->employee_id,
                'employee_number' => $employee?->employee_number,
                'name' => trim((string) $employee?->first_name.' '.(string) $employee?->last_name),
            ];
        })->values()->all();
    }

    public function saveDrafts(User $actor, SupplementaryExamOffering $seed, array $marks): array
    {
        $this->professor($actor, Governance::ENTER);
        $this->ready();

        return DB::transaction(function () use ($actor, $seed, $marks): array {
            [$period, $offering] = $this->lockGraph($seed);
            $this->assertAssigned($actor, $offering, true);
            if ($period->status !== 'grading_open') {
                $this->fail('Supplementary grading is not open.', 'supplementary_grading_not_open', 409);
            }

            $registrations = $this->lockedRoster($offering);
            $allowed = $registrations->keyBy('supplementary_exam_registration_id');
            $limits = $this->grades->gradingPolicyLimits();
            foreach ($marks as $item) {
                $id = (int) ($item['supplementary_exam_registration_id'] ?? 0);
                $registration = $allowed->get($id);
                if (! $registration) {
                    $this->fail('The registration is not in the fixed roster.', 'supplementary_grade_registration_invalid', 422);
                }
                $mark = filter_var($item['theoretical_mark'] ?? null, FILTER_VALIDATE_FLOAT);
                if ($mark === false || $mark < 0 || $mark > $limits['theoretical_max_mark']) {
                    $this->fail('The theoretical mark is outside the approved range.', 'supplementary_theoretical_mark_out_of_range', 422);
                }

                $result = SupplementaryExamGradeResult::query()
                    ->where('supplementary_exam_registration_id', $id)
                    ->lockForUpdate()
                    ->first();
                if ($result && ! in_array($result->status, ['draft', 'returned'], true)) {
                    $this->fail('Grades are locked in this state.', 'supplementary_grade_locked', 409);
                }
                $from = $result?->status;
                $result ??= new SupplementaryExamGradeResult(['supplementary_exam_registration_id' => $id]);
                $result->fill([
                    'supplementary_exam_offering_id' => $offering->getKey(),
                    'student_course_registration_id' => $registration->student_course_registration_id,
                    'student_id' => $registration->student_id,
                    'theoretical_mark' => round((float) $mark, 2),
                    'status' => $from === 'returned' ? 'returned' : 'draft',
                    'submission_version' => (int) ($result->submission_version ?: 1),
                    'last_edited_by_user_id' => $actor->user_id,
                ]);
                $result->save();
                $this->event($result, null, 'draft_saved', $from, $result->status, $actor);
            }

            return $this->rosterPayload($actor, $offering->fresh());
        }, 3);
    }

    public function submit(User $actor, SupplementaryExamOffering $seed, bool $resubmit = false): array
    {
        $this->professor($actor, Governance::ENTER);
        $this->ready();

        return DB::transaction(function () use ($actor, $seed, $resubmit): array {
            [$period, $offering] = $this->lockGraph($seed);
            $assignment = $this->assertAssigned($actor, $offering, true);
            if ($period->status !== 'grading_open') {
                $this->fail('Supplementary grading is not open.', 'supplementary_grading_not_open', 409);
            }

            $registrations = $this->lockedRoster($offering);
            if ($registrations->isEmpty()) {
                $this->fail('The fixed roster is empty.', 'supplementary_grade_roster_empty', 409);
            }
            $results = SupplementaryExamGradeResult::query()
                ->where('supplementary_exam_offering_id', $offering->getKey())
                ->orderBy('supplementary_exam_grade_result_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('supplementary_exam_registration_id');
            $current = SupplementaryExamGradeSubmission::query()
                ->where('supplementary_exam_offering_id', $offering->getKey())
                ->orderByDesc('submission_version')
                ->lockForUpdate()
                ->first();
            if ($resubmit && $current?->status !== 'returned') {
                $this->fail('Resubmission is available only after return.', 'supplementary_grade_not_returned', 409);
            }
            if (! $resubmit && $current) {
                $this->fail('This batch was already submitted.', 'supplementary_grade_already_submitted', 409);
            }
            foreach ($registrations as $registration) {
                $result = $results->get($registration->getKey());
                if (! $result
                    || $result->theoretical_mark === null
                    || ! in_array($result->status, ['draft', 'returned'], true)) {
                    $this->fail('Every fixed-roster mark must be complete.', 'supplementary_grade_batch_incomplete', 422);
                }
                if ((int) $result->supplementary_exam_registration_id !== (int) $registration->getKey()
                    || (int) $result->supplementary_exam_offering_id !== (int) $offering->getKey()
                    || (int) $result->student_course_registration_id !== (int) $registration->student_course_registration_id
                    || (int) $result->student_id !== (int) $registration->student_id) {
                    $this->fail('The result batch does not exactly match the fixed roster.', 'supplementary_grade_roster_mismatch', 409);
                }
                if ($current && (int) $result->submission_version !== (int) $current->submission_version) {
                    $this->fail('The result batch belongs to a stale submission version.', 'supplementary_grade_version_mismatch', 409);
                }
            }
            if ($results->count() !== $registrations->count()) {
                $this->fail('The result batch does not exactly match the fixed roster.', 'supplementary_grade_roster_mismatch', 409);
            }

            $version = $current ? (int) $current->submission_version + 1 : 1;
            $submission = SupplementaryExamGradeSubmission::query()->create([
                'supplementary_exam_offering_id' => $offering->getKey(),
                'grader_assignment_id' => $assignment->getKey(),
                'submission_version' => $version,
                'status' => 'submitted',
                'submitted_by_user_id' => $actor->user_id,
                'submitted_at' => now(),
            ]);
            foreach ($results as $result) {
                $from = $result->status;
                $result->update(['status' => 'submitted', 'submission_version' => $version]);
                $this->event($result, $submission, 'submitted', $from, 'submitted', $actor);
            }
            if ($this->allPeriodOfferingsAt($period, ['submitted', 'approved', 'published'])) {
                $this->periodStatus($period, $actor, 'grading_submitted', 'grading_submitted');
            }

            return $this->rosterPayload($actor, $offering->fresh());
        }, 3);
    }

    public function review(User $actor, int $submissionId, string $action, ?string $reason = null): array
    {
        $permission = $action === 'publish' ? Governance::PUBLISH : Governance::REVIEW;
        $this->exam($actor, $permission);
        $this->ready();

        return DB::transaction(function () use ($actor, $submissionId, $action, $reason): array {
            $seed = SupplementaryExamGradeSubmission::query()->findOrFail($submissionId);
            $offeringSeed = SupplementaryExamOffering::query()->findOrFail($seed->supplementary_exam_offering_id);
            [$period, $offering] = $this->lockGraph($offeringSeed);
            if (! $this->scope->canMutateProgram($actor, (int) $offering->academic_program_id)) {
                $this->fail('The supplementary offering is outside the assigned data scope.', 'supplementary_grade_out_of_scope', 403);
            }
            SupplementaryExamGraderAssignment::query()
                ->where('supplementary_exam_offering_id', $offering->getKey())
                ->where('current_slot', 1)
                ->lockForUpdate()
                ->first();

            $submissions = SupplementaryExamGradeSubmission::query()
                ->where('supplementary_exam_offering_id', $offering->getKey())
                ->orderBy('supplementary_exam_grade_submission_id')
                ->lockForUpdate()
                ->get();
            $submission = $submissions->firstWhere('supplementary_exam_grade_submission_id', $submissionId);
            if (! $submission) {
                $this->fail('The submission does not belong to the locked offering.', 'supplementary_grade_submission_mismatch', 409);
            }
            $latest = $submissions->max('submission_version');
            if ((int) $submission->submission_version !== (int) $latest) {
                $this->fail('A stale submission version cannot be reviewed.', 'supplementary_grade_stale_submission', 409);
            }

            $from = (string) $submission->status;
            $to = $this->reviewTargetStatus($action, $from, $reason);
            $this->assertReviewPeriodState($period, $action, $from);
            $results = $this->lockExactSubmissionRoster($offering, $submission);

            if ($action === 'publish' && $from === 'published') {
                return $this->rosterPayload($actor, $offering);
            }

            $submission->update([
                'status' => $to,
                'reviewed_by_user_id' => $action === 'publish' ? $submission->reviewed_by_user_id : $actor->user_id,
                'reviewed_at' => $action === 'publish' ? $submission->reviewed_at : now(),
                'review_reason' => $reason,
                'published_by_user_id' => $action === 'publish' ? $actor->user_id : null,
                'published_at' => $action === 'publish' ? now() : null,
            ]);
            foreach ($results as $result) {
                $resultFrom = $result->status;
                $result->update(['status' => $to, 'published_at' => $action === 'publish' ? now() : null]);
                $this->event($result, $submission, $to, $resultFrom, $to, $actor, $reason);
            }

            if ($action === 'return') {
                $this->periodStatus($period, $actor, 'grading_open', 'grading_returned');
            } elseif ($action === 'approve' && $this->allPeriodOfferingsAt($period, ['approved', 'published'])) {
                $this->periodStatus($period, $actor, 'results_approved', 'grading_approved');
            } elseif ($action === 'publish' && $this->allPeriodOfferingsAt($period, ['published'])) {
                $this->periodStatus($period, $actor, 'results_published', 'grading_published');
            }

            return $this->rosterPayload($actor, $offering->fresh());
        }, 3);
    }

    public function openGrading(User $actor, SupplementaryExamPeriod $seed): SupplementaryExamPeriod
    {
        $this->exam($actor, Governance::REVIEW);
        $this->ready();
        if (! $this->scope->hasActualUniversityScope($actor)) {
            $this->fail('Opening grading for a whole period requires an actual university scope.', 'supplementary_grading_out_of_scope', 403);
        }

        return DB::transaction(function () use ($actor, $seed): SupplementaryExamPeriod {
            $period = SupplementaryExamPeriod::query()->lockForUpdate()->findOrFail($seed->getKey());
            if (! in_array($period->status, ['registration_closed', 'grading_open'], true)) {
                $this->fail('The fixed registration roster must be closed first.', 'supplementary_grading_open_invalid', 409);
            }
            $offerings = $period->supplementaryExamOfferings()
                ->orderBy('supplementary_exam_offering_id')
                ->lockForUpdate()
                ->get();
            $this->lockExactPeriodRoster(
                $period,
                $offerings,
                $period->status === 'registration_closed',
            );
            if ($period->status === 'grading_open') {
                return $period->fresh();
            }

            $this->periodStatus($period, $actor, 'grading_open', 'grading_opened');

            return $period->fresh();
        }, 3);
    }

    public function assign(User $actor, SupplementaryExamOffering $seed, int $facultyId): SupplementaryExamGraderAssignment
    {
        $this->exam($actor, Governance::ASSIGN);
        $this->ready();

        return DB::transaction(function () use ($actor, $seed, $facultyId): SupplementaryExamGraderAssignment {
            [$period, $offering] = $this->lockGraph($seed);
            if (! $this->scope->canMutateProgram($actor, (int) $offering->academic_program_id)) {
                $this->fail('The supplementary offering is outside the assigned data scope.', 'supplementary_grade_out_of_scope', 403);
            }
            if (! in_array($period->status, ['registration_closed', 'grading_open'], true)) {
                $this->fail('The grader cannot be changed in this period state.', 'supplementary_grader_assignment_locked', 409);
            }

            $current = SupplementaryExamGraderAssignment::query()
                ->where('supplementary_exam_offering_id', $offering->getKey())
                ->where('current_slot', 1)
                ->lockForUpdate()
                ->first();
            $latest = SupplementaryExamGradeSubmission::query()
                ->where('supplementary_exam_offering_id', $offering->getKey())
                ->orderByDesc('submission_version')
                ->lockForUpdate()
                ->first();
            if ($latest && in_array($latest->status, ['submitted', 'approved', 'published'], true)) {
                $this->fail('The grader assignment is locked after submission.', 'supplementary_grader_assignment_locked', 409);
            }

            $faculty = FacultyMember::query()
                ->whereKey($facultyId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();
            if (! $this->scope->canMutateFacultyMember($actor, $faculty)) {
                $this->fail('The selected grader is outside the assigned data scope.', 'supplementary_grader_out_of_scope', 403);
            }
            $hasProfessor = User::query()
                ->where('employee_id', $faculty->employee_id)
                ->whereHas('accountStatus', fn ($status) => $status->where('status_code', 'active'))
                ->whereHas('userRoleRecords', fn ($roles) => $roles
                    ->where('is_active', true)
                    ->whereHas('role', fn ($role) => $role
                        ->where('role_code', 'doctor_instructor')
                        ->where('is_active', true)))
                ->whereHas('userRoleRecords', fn ($roles) => $roles
                    ->where('is_active', true)
                    ->whereHas('role', fn ($role) => $role
                        ->where('is_active', true)
                        ->whereHas('rolePermissions.permission', fn ($permission) => $permission
                            ->where('permission_code', Governance::VIEW)
                            ->where('is_active', true))))
                ->whereHas('userRoleRecords', fn ($roles) => $roles
                    ->where('is_active', true)
                    ->whereHas('role', fn ($role) => $role
                        ->where('is_active', true)
                        ->whereHas('rolePermissions.permission', fn ($permission) => $permission
                            ->where('permission_code', Governance::ENTER)
                            ->where('is_active', true))))
                ->exists();
            if (! $hasProfessor) {
                $this->fail('The selected grader is not an active professor.', 'supplementary_grader_invalid', 422);
            }

            if ($current && (int) $current->faculty_member_id === $facultyId) {
                return $current;
            }
            if ($current) {
                $current->update(['current_slot' => null, 'ended_at' => now()]);
            }

            return SupplementaryExamGraderAssignment::query()->create([
                'supplementary_exam_offering_id' => $offering->getKey(),
                'faculty_member_id' => $facultyId,
                'current_slot' => 1,
                'assigned_by_user_id' => $actor->user_id,
                'assigned_at' => now(),
            ]);
        }, 3);
    }

    private function rosterPayload(User $actor, SupplementaryExamOffering $offering): array
    {
        return $this->rosterPayloads($actor, collect([$offering]))[0];
    }

    /**
     * @param  Collection<int, SupplementaryExamOffering>  $offerings
     * @param  Collection<int, int>|null  $mutableProgramIds
     * @return list<array<string, mixed>>
     */
    private function rosterPayloads(
        User $actor,
        Collection $offerings,
        ?Collection $mutableProgramIds = null,
    ): array
    {
        if ($offerings->isEmpty()) {
            return [];
        }
        foreach ($offerings as $offering) {
            $offering->loadMissing(['period', 'course', 'academicProgram.department']);
        }
        $mutableProgramIds ??= $this->mutableProgramIds($actor, $offerings);
        $offeringIds = $offerings->pluck('supplementary_exam_offering_id')->map(fn ($id) => (int) $id)->values();
        $registrations = SupplementaryExamRegistration::query()
            ->with([
                'student',
                'originalRegistration.studentCourseResult',
                'originalRegistration.courseOffering.gradeComponents',
                'gradeResult',
            ])
            ->whereIn('supplementary_exam_offering_id', $offeringIds)
            ->where('status', 'registered')
            ->where('current_slot', 1)
            ->orderBy('supplementary_exam_registration_id')
            ->get()
            ->groupBy('supplementary_exam_offering_id');
        $submissions = SupplementaryExamGradeSubmission::query()
            ->whereIn('supplementary_exam_offering_id', $offeringIds)
            ->orderByDesc('submission_version')
            ->orderByDesc('supplementary_exam_grade_submission_id')
            ->get()
            ->groupBy('supplementary_exam_offering_id');
        $assignments = SupplementaryExamGraderAssignment::query()
            ->with('facultyMember.employee')
            ->whereIn('supplementary_exam_offering_id', $offeringIds)
            ->where('current_slot', 1)
            ->get()
            ->keyBy('supplementary_exam_offering_id');
        $registrationIds = $registrations->flatten(1)->pluck('supplementary_exam_registration_id');
        $targetRegistrationIds = $registrations->flatten(1)->pluck('student_course_registration_id');
        $materializations = collect();
        $materializationProvenanceReady = MaterializationGovernance::materializationTableAvailable();
        if ($materializationProvenanceReady && $registrationIds->isNotEmpty()) {
            $materializations = SupplementaryExamMaterialization::query()
                ->where(function ($query) use ($registrationIds, $targetRegistrationIds): void {
                    $query->whereIn('supplementary_exam_registration_id', $registrationIds)
                        ->orWhereIn('student_course_registration_id', $targetRegistrationIds);
                })
                ->get([
                    'supplementary_exam_registration_id',
                    'supplementary_exam_offering_id',
                    'supplementary_exam_grade_result_id',
                    'student_course_registration_id',
                ]);
        }
        $materializationsByRegistration = $materializations->groupBy('supplementary_exam_registration_id');
        $materializationsByTarget = $materializations->groupBy('student_course_registration_id');

        $permissions = $actor->effectivePermissions();
        $actorFacultyId = $actor->isProfessor()
            ? (int) FacultyMember::query()->where('employee_id', $actor->employee_id)->where('is_active', true)->value('faculty_member_id')
            : 0;
        $limits = $this->grades->gradingPolicyLimits();

        return $offerings->map(function (SupplementaryExamOffering $offering) use (
            $actor,
            $actorFacultyId,
            $assignments,
            $materializationProvenanceReady,
            $materializationsByRegistration,
            $materializationsByTarget,
            $mutableProgramIds,
            $permissions,
            $registrations,
            $submissions,
            $limits,
        ): array {
            $roster = $registrations->get($offering->getKey(), collect())->values();
            $submission = $submissions->get($offering->getKey(), collect())->first();
            $assignment = $assignments->get($offering->getKey());
            $periodStatus = (string) $offering->period?->status;
            $workflowStatus = $submission?->status
                ?? ($roster->contains(fn ($registration) => $registration->gradeResult !== null) ? 'draft' : 'waiting');
            $isAssignedProfessor = $actorFacultyId > 0
                && (int) $assignment?->faculty_member_id === $actorFacultyId;
            $inProgramScope = $actor->isExamOfficer()
                && $mutableProgramIds->contains((int) $offering->academic_program_id);
            $canEdit = $materializationProvenanceReady
                && $actor->isProfessor()
                && $permissions->contains(Governance::ENTER)
                && $isAssignedProfessor
                && $periodStatus === 'grading_open'
                && in_array($submission?->status, [null, 'returned'], true);
            $batchComplete = $roster->isNotEmpty() && $roster->every(fn ($registration) =>
                $registration->gradeResult !== null
                && $registration->gradeResult->theoretical_mark !== null
                && in_array($registration->gradeResult->status, ['draft', 'returned'], true));
            $assignmentLocked = $submission
                && in_array($submission->status, ['submitted', 'approved', 'published'], true);
            $canReview = $inProgramScope && $actor->isExamOfficer() && $permissions->contains(Governance::REVIEW);
            $canPublish = $inProgramScope && $actor->isExamOfficer() && $permissions->contains(Governance::PUBLISH);

            $rosterPayload = $roster->map(function ($registration) use (
                $canEdit,
                $materializationsByRegistration,
                $materializationsByTarget,
                $offering,
            ): array {
                $practical = $registration->originalRegistration?->studentCourseResult?->practical_total;
                $theory = $registration->gradeResult?->theoretical_mark;
                $requiredComponents = $registration->originalRegistration?->courseOffering?->gradeComponents
                    ?->filter(fn ($component): bool => (bool) $component->is_required)
                    ->whereIn('component_type', ['theoretical', 'practical'])
                    ?? collect();
                $theoreticalComponents = $requiredComponents->where('component_type', 'theoretical');
                $practicalComponents = $requiredComponents->where('component_type', 'practical');
                $requiresTheoretical = $theoreticalComponents->isNotEmpty();
                $requiresPractical = $practicalComponents->isNotEmpty();
                $preview = $theory === null ? null : $this->grades->buildCalculationForRequiredParts(
                    (float) $theory,
                    $practical === null ? null : (float) $practical,
                    $requiresTheoretical,
                    $requiresPractical,
                    (float) $theoreticalComponents->sum('max_mark'),
                    (float) $practicalComponents->sum('max_mark'),
                );
                $materialization = $materializationsByRegistration
                    ->get($registration->getKey(), collect())
                    ->first(fn (SupplementaryExamMaterialization $row): bool =>
                        (int) $row->supplementary_exam_offering_id === (int) $offering->getKey()
                    );
                $conflictingMaterialization = $materializationsByTarget
                    ->get((int) $registration->student_course_registration_id, collect())
                    ->first(fn (SupplementaryExamMaterialization $row): bool =>
                        (int) $row->supplementary_exam_registration_id !== (int) $registration->getKey()
                        || (int) $row->supplementary_exam_offering_id !== (int) $offering->getKey()
                    );
                $materialized = $materialization !== null
                    && (int) $materialization->supplementary_exam_offering_id === (int) $offering->getKey()
                    && (int) $materialization->supplementary_exam_grade_result_id
                        === (int) ($registration->gradeResult?->getKey() ?? 0);

                return [
                    'supplementary_exam_registration_id' => (int) $registration->getKey(),
                    'student_course_registration_id' => (int) $registration->student_course_registration_id,
                    'supplementary_exam_grade_result_id' => $registration->gradeResult?->getKey(),
                    'student' => $registration->student,
                    'preserved_practical_mark' => $practical,
                    'supplementary_theoretical_mark' => $theory,
                    'result_status' => $registration->gradeResult?->status,
                    'submission_version' => $registration->gradeResult?->submission_version,
                    'preview' => $preview,
                    'official_record_materialized' => $materialized,
                    'materialization_conflict_reason' => $conflictingMaterialization
                        ? 'regular_attempt_already_materialized'
                        : null,
                    'can_edit' => $canEdit && ! $materialized && ! $conflictingMaterialization,
                ];
            })->all();
            $gradedCount = collect($rosterPayload)->whereNotNull('supplementary_theoretical_mark')->count();
            $publishedCount = collect($rosterPayload)->where('result_status', 'published')->count();
            $materializedCount = collect($rosterPayload)->where('official_record_materialized', true)->count();
            $hasOfficialTargetLock = collect($rosterPayload)->contains(fn (array $candidate): bool =>
                $candidate['official_record_materialized']
                || $candidate['materialization_conflict_reason'] !== null
            );
            $assignmentPayload = $this->assignmentPayload($assignment);

            return [
                'offering' => $offering,
                'period_status' => $periodStatus,
                'workflow_status' => $workflowStatus,
                'submission' => $submission,
                'current_grader_assignment' => $assignmentPayload,
                'grader_assignment' => $assignmentPayload,
                'grading_limits' => [
                    'theoretical_min' => 0,
                    'theoretical_max' => (float) $limits['theoretical_max_mark'],
                    'theoretical_step' => 0.01,
                ],
                'can_edit' => $canEdit && ! $hasOfficialTargetLock,
                'action_flags' => [
                    'can_edit' => $canEdit && ! $hasOfficialTargetLock,
                    'can_submit' => $canEdit && ! $hasOfficialTargetLock && $submission === null && $batchComplete,
                    'can_resubmit' => $canEdit && ! $hasOfficialTargetLock && $submission?->status === 'returned' && $batchComplete,
                    'can_assign_grader' => $inProgramScope
                        && $permissions->contains(Governance::ASSIGN)
                        && in_array($periodStatus, ['registration_closed', 'grading_open'], true)
                        && ! $assignmentLocked,
                    'can_return' => $canReview
                        && $submission?->status === 'submitted'
                        && in_array($periodStatus, ['grading_open', 'grading_submitted'], true),
                    'can_approve' => $canReview
                        && $submission?->status === 'submitted'
                        && in_array($periodStatus, ['grading_open', 'grading_submitted'], true),
                    'can_publish' => $canPublish
                        && $submission?->status === 'approved'
                        && $periodStatus === 'results_approved',
                    'can_materialize' => $inProgramScope
                        && $permissions->contains(MaterializationGovernance::MATERIALIZE)
                        && $submission?->status === 'published'
                        && $periodStatus === MaterializationGovernance::SOURCE_PERIOD_STATUS
                        && $roster->isNotEmpty()
                        && $materializedCount === 0,
                ],
                'counts' => [
                    'registered' => $roster->count(),
                    'graded' => $gradedCount,
                    'published' => $publishedCount,
                    'materialized' => $materializedCount,
                ],
                'roster' => $rosterPayload,
            ];
        })->values()->all();
    }

    private function lockGraph(SupplementaryExamOffering $seed): array
    {
        $offeringSeed = SupplementaryExamOffering::query()->findOrFail($seed->getKey());
        $period = SupplementaryExamPeriod::query()
            ->lockForUpdate()
            ->findOrFail($offeringSeed->supplementary_exam_period_id);
        $offering = SupplementaryExamOffering::query()->lockForUpdate()->findOrFail($seed->getKey());

        return [$period, $offering];
    }

    private function lockedRoster(SupplementaryExamOffering $offering): Collection
    {
        $allRegistrations = SupplementaryExamRegistration::query()
            ->where('supplementary_exam_offering_id', $offering->getKey())
            ->orderBy('supplementary_exam_registration_id')
            ->lockForUpdate()
            ->get();
        if ($allRegistrations->contains(fn (SupplementaryExamRegistration $registration): bool =>
            ! (($registration->status === 'registered' && (int) $registration->current_slot === 1)
                || ($registration->status === 'cancelled' && $registration->current_slot === null)))) {
            $this->fail('The fixed roster contains an invalid registration state.', 'supplementary_grade_roster_mismatch', 409);
        }

        $roster = $allRegistrations
            ->where('status', 'registered')
            ->filter(fn (SupplementaryExamRegistration $registration): bool => (int) $registration->current_slot === 1)
            ->values();
        if ($roster->isEmpty()) {
            $this->fail('The fixed roster is empty.', 'supplementary_grade_roster_empty', 409);
        }

        $this->lockAndValidateFixedRosterTargets($roster, collect([$offering]));

        return $roster;
    }

    /**
     * Revalidate the fixed roster against the canonical regular attempts in one
     * locked, batched graph. A stale eligibility decision must not progress into
     * grading simply because its supplementary row still looks current.
     *
     * @param Collection<int, SupplementaryExamRegistration> $roster
     * @param Collection<int, SupplementaryExamOffering> $offerings
     */
    private function lockAndValidateFixedRosterTargets(Collection $roster, Collection $offerings): void
    {
        $targetIds = $roster->pluck('student_course_registration_id')->map(fn ($id): int => (int) $id);
        if ($targetIds->contains(fn (int $id): bool => $id <= 0)
            || $targetIds->unique()->count() !== $targetIds->count()) {
            $this->fail('A regular registration appears more than once in the fixed roster.', 'supplementary_grade_roster_mismatch', 409);
        }
        $crossPeriodDuplicate = SupplementaryExamRegistration::query()
            ->whereIn('student_course_registration_id', $targetIds)
            ->where('status', 'registered')
            ->where('current_slot', 1)
            ->whereNotIn('supplementary_exam_registration_id', $roster->modelKeys())
            ->whereHas('offering.period', fn ($period) => $period
                ->whereIn('status', \App\Support\SupplementaryExamRegistrationGovernance::FIXED_ROSTER_PERIOD_STATUSES))
            ->exists();
        if ($crossPeriodDuplicate) {
            $this->fail(
                'The same official attempt is fixed in another supplementary period.',
                'supplementary_grade_cross_period_target_conflict',
                409,
            );
        }

        // Resolve the immutable parent ids without locks, then lock regular
        // offerings before registrations to match ordinary grade/registration
        // mutations and the materialization workflow.
        $targetOfferingMap = StudentCourseRegistration::query()
            ->whereIn('student_course_registration_id', $targetIds)
            ->orderBy('student_course_registration_id')
            ->pluck('course_offering_id', 'student_course_registration_id');
        $targetOfferingIds = $targetOfferingMap->values()->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        $targetOfferings = CourseOffering::query()
            ->whereIn('course_offering_id', $targetOfferingIds)
            ->orderBy('course_offering_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('course_offering_id');
        $targets = StudentCourseRegistration::query()
            ->whereIn('student_course_registration_id', $targetIds)
            ->orderBy('student_course_registration_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('student_course_registration_id');
        $targetResults = StudentCourseResult::query()
            ->whereIn('student_course_registration_id', $targetIds)
            ->orderBy('student_course_result_id')
            ->lockForUpdate()
            ->get()
            ->groupBy('student_course_registration_id');
        $offeringIds = $offerings->pluck('supplementary_exam_offering_id')->map(fn ($id): int => (int) $id);
        $offeringsById = $offerings->keyBy('supplementary_exam_offering_id');
        $sources = SupplementaryExamOfferingSource::query()
            ->whereIn('supplementary_exam_offering_id', $offeringIds)
            ->orderBy('supplementary_exam_offering_source_id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn ($source): string => $source->supplementary_exam_offering_id.':'.$source->course_offering_id);
        $registrationStatuses = DB::table('registration_statuses')
            ->whereIn('registration_status_id', $targets->pluck('registration_status_id'))
            ->orderBy('registration_status_id')
            ->lockForUpdate()
            ->pluck('status_code', 'registration_status_id');
        $resultStatuses = DB::table('result_statuses')
            ->where('is_active', true)
            ->orderBy('result_status_id')
            ->lockForUpdate()
            ->pluck('status_code', 'result_status_id');
        $approvals = GradeApproval::query()
            ->whereIn('course_offering_id', $targetOfferingIds)
            ->orderBy('grade_approval_id')
            ->lockForUpdate()
            ->get()
            ->groupBy('course_offering_id')
            ->map(fn (Collection $rows) => $rows->last());
        $approvedStatusIds = DB::table('approval_statuses')
            ->where('status_code', 'approved')
            ->where('is_active', true)
            ->orderBy('approval_status_id')
            ->lockForUpdate()
            ->pluck('approval_status_id');

        if ($targetOfferingMap->count() !== $targetIds->count()
            || $targets->count() !== $targetIds->count()
            || $targets->contains(fn (StudentCourseRegistration $target): bool =>
                (int) $target->course_offering_id !== (int) $targetOfferingMap->get($target->getKey()))
            || $targetOfferings->count() !== $targetOfferingIds->count()
            || $approvedStatusIds->count() !== 1) {
            $this->fail('The fixed roster no longer matches its academic source.', 'supplementary_grade_roster_mismatch', 409);
        }

        SupplementaryExamTargetGuard::assertAllAvailable($targetIds);
        $approvedStatusId = (int) $approvedStatusIds->first();
        foreach ($roster as $registration) {
            $offering = $offeringsById->get((int) $registration->supplementary_exam_offering_id);
            $target = $targets->get((int) $registration->student_course_registration_id);
            $results = $targetResults->get((int) $registration->student_course_registration_id, collect());
            $result = $results->first();
            $targetOffering = $targetOfferings->get((int) ($target?->course_offering_id ?? 0));
            $approval = $approvals->get((int) ($target?->course_offering_id ?? 0));
            $registrationStatus = $registrationStatuses->get((int) ($target?->registration_status_id ?? 0));
            $resultStatus = $resultStatuses->get((int) ($result?->result_status_id ?? 0));
            $sourceKey = $registration->supplementary_exam_offering_id.':'.($target?->course_offering_id ?? 0);

            if (! $offering
                || ! $target
                || $results->count() !== 1
                || ! $result
                || ! $targetOffering
                || ! $approval
                || ! in_array($registration->eligibility_reason, ['failed_theoretical', 'voluntarily_deferred_theoretical'], true)
                || (int) $target->student_id !== (int) $registration->student_id
                || (int) $result->student_course_registration_id !== (int) $target->getKey()
                || (int) $targetOffering->course_id !== (int) $offering->course_id
                || (int) $targetOffering->academic_program_id !== (int) $offering->academic_program_id
                || ! $sources->has($sourceKey)
                || ! in_array($registrationStatus, StudentCourseRegistration::HISTORICAL_ATTEMPT_STATUSES, true)
                || (bool) $result->is_deprived
                || $resultStatus === 'deprived'
                || ($target->result_status_id !== null
                    && (int) $target->result_status_id !== (int) $result->result_status_id)
                || (int) $approval->approval_status_id !== $approvedStatusId
                || ($registration->eligibility_reason === 'failed_theoretical' && $resultStatus !== 'failed')
                || ($registration->eligibility_reason === 'voluntarily_deferred_theoretical'
                    && ! in_array($resultStatus, ['incomplete', 'failed'], true))) {
                $this->fail('The fixed-roster eligibility or academic source has drifted.', 'supplementary_grade_eligibility_drift', 409);
            }
        }
    }

    private function assertNoPrematureGradingArtifacts(Collection $offerings): void
    {
        $offeringIds = $offerings->pluck('supplementary_exam_offering_id')->map(fn ($id): int => (int) $id);
        $results = SupplementaryExamGradeResult::query()
            ->whereIn('supplementary_exam_offering_id', $offeringIds)
            ->orderBy('supplementary_exam_grade_result_id')
            ->lockForUpdate()
            ->get();
        $submissions = SupplementaryExamGradeSubmission::query()
            ->whereIn('supplementary_exam_offering_id', $offeringIds)
            ->orderBy('supplementary_exam_grade_submission_id')
            ->lockForUpdate()
            ->get();
        $events = SupplementaryExamGradeEvent::query()
            ->whereIn('supplementary_exam_grade_result_id', $results->modelKeys())
            ->orderBy('supplementary_exam_grade_event_id')
            ->lockForUpdate()
            ->get();
        if ($results->isNotEmpty() || $submissions->isNotEmpty() || $events->isNotEmpty()) {
            $this->fail(
                'Grading artifacts already exist before grading was opened.',
                'supplementary_grading_premature_artifacts',
                409,
            );
        }
    }

    private function assertNoPrematureMaterializations(Collection $offerings): void
    {
        $materializations = SupplementaryExamMaterialization::query()
            ->whereIn('supplementary_exam_offering_id', $offerings->modelKeys())
            ->orderBy('supplementary_exam_materialization_id')
            ->lockForUpdate()
            ->get();
        if ($materializations->isNotEmpty()) {
            $this->fail(
                'Materialization provenance exists before grading was opened.',
                'supplementary_grading_premature_artifacts',
                409,
            );
        }
    }

    /** @return Collection<int, int> */
    private function mutableProgramIds(User $actor, Collection $offerings): Collection
    {
        if (! $actor->isExamOfficer() || $offerings->isEmpty()) {
            return collect();
        }

        $scopes = collect($this->scope->scopes($actor));
        $programIds = $offerings->pluck('academic_program_id')->map(fn ($id): int => (int) $id)->unique();
        if ($scopes->contains(fn (array $scope): bool => $scope['type'] === 'university')) {
            return $programIds->values();
        }

        $directPrograms = $scopes->where('type', 'program')->pluck('id')->map(fn ($id): int => (int) $id);
        $departments = $scopes->where('type', 'department')->pluck('id')->map(fn ($id): int => (int) $id);
        $colleges = $scopes->where('type', 'college')->pluck('id')->map(fn ($id): int => (int) $id);

        return $offerings->filter(function (SupplementaryExamOffering $offering) use (
            $colleges,
            $departments,
            $directPrograms,
        ): bool {
            $program = $offering->academicProgram;

            return $directPrograms->contains((int) $offering->academic_program_id)
                || $departments->contains((int) $program?->department_id)
                || $colleges->contains((int) $program?->department?->college_id);
        })->pluck('academic_program_id')->map(fn ($id): int => (int) $id)->unique()->values();
    }

    private function lockExactPeriodRoster(
        SupplementaryExamPeriod $period,
        Collection $offerings,
        bool $assertNoGradingArtifacts = false,
    ): Collection
    {
        $offeringIds = $offerings
            ->pluck('supplementary_exam_offering_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
        if ($offeringIds === []) {
            $this->fail('The supplementary period has no offerings.', 'supplementary_grade_roster_empty', 409);
        }
        $allRegistrations = SupplementaryExamRegistration::query()
            ->whereIn('supplementary_exam_offering_id', $offeringIds)
            ->orderBy('supplementary_exam_registration_id')
            ->lockForUpdate()
            ->get();
        if ($allRegistrations->contains(fn (SupplementaryExamRegistration $registration) =>
            ! (($registration->status === 'registered' && (int) $registration->current_slot === 1)
                || ($registration->status === 'cancelled' && $registration->current_slot === null)))) {
            $this->fail('The fixed roster contains an invalid registration state.', 'supplementary_grade_roster_mismatch', 409);
        }
        $roster = $allRegistrations
            ->where('status', 'registered')
            ->filter(fn (SupplementaryExamRegistration $registration) => (int) $registration->current_slot === 1)
            ->values();
        if ($roster->isEmpty()) {
            $this->fail('The fixed roster is empty.', 'supplementary_grade_roster_empty', 409);
        }
        if ($assertNoGradingArtifacts) {
            $this->assertNoPrematureGradingArtifacts($offerings);
        }
        $this->lockAndValidateFixedRosterTargets($roster, $offerings);
        if ($assertNoGradingArtifacts) {
            $this->assertNoPrematureMaterializations($offerings);
        }

        return $roster;
    }

    private function lockExactSubmissionRoster(
        SupplementaryExamOffering $offering,
        SupplementaryExamGradeSubmission $submission,
    ): Collection {
        $roster = $this->lockedRoster($offering);
        $results = SupplementaryExamGradeResult::query()
            ->where('supplementary_exam_offering_id', $offering->getKey())
            ->orderBy('supplementary_exam_grade_result_id')
            ->lockForUpdate()
            ->get();
        $rosterIds = $roster->pluck('supplementary_exam_registration_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $resultRosterIds = $results->pluck('supplementary_exam_registration_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        if ($rosterIds !== $resultRosterIds) {
            $this->fail('The result batch does not exactly match the fixed roster.', 'supplementary_grade_roster_mismatch', 409);
        }
        $registrations = $roster->keyBy('supplementary_exam_registration_id');
        foreach ($results as $result) {
            $registration = $registrations->get((int) $result->supplementary_exam_registration_id);
            if (! $registration
                || $result->theoretical_mark === null
                || (int) $result->supplementary_exam_offering_id !== (int) $offering->getKey()
                || (int) $result->student_course_registration_id !== (int) $registration->student_course_registration_id
                || (int) $result->student_id !== (int) $registration->student_id
                || (int) $result->submission_version !== (int) $submission->submission_version
                || (string) $result->status !== (string) $submission->status) {
                $this->fail('The reviewed result batch is stale or inconsistent.', 'supplementary_grade_version_mismatch', 409);
            }
        }

        return $results;
    }

    private function reviewTargetStatus(string $action, string $from, ?string $reason): string
    {
        if ($action==='return') {
            if ($from !== 'submitted' || trim((string) $reason) === '') {
                $this->fail('A return reason is required for a submitted batch.', 'supplementary_grade_return_invalid', 422);
            }

            return 'returned';
        }
        if ($action==='approve') {
            if ($from !== 'submitted') {
                $this->fail('Only a submitted batch may be approved.', 'supplementary_grade_approve_invalid', 409);
            }

            return 'approved';
        }
        if ($action==='publish') {
            if (! in_array($from, ['approved', 'published'], true)) {
                $this->fail('Only an approved batch may be published.', 'supplementary_grade_publish_invalid', 409);
            }

            return 'published';
        }

        $this->fail('The requested grade action is invalid.', 'supplementary_grade_action_invalid', 422);
    }

    private function assertReviewPeriodState(
        SupplementaryExamPeriod $period,
        string $action,
        string $submissionStatus,
    ): void {
        if ($period->status === MaterializationGovernance::TERMINAL_PERIOD_STATUS) {
            $this->fail('A materialized supplementary period is terminal.', 'supplementary_period_terminal', 409);
        }
        if (in_array($action, ['return', 'approve'], true)
            && ! in_array($period->status, ['grading_open', 'grading_submitted'], true)) {
            $this->fail('The period state does not allow this review action.', 'supplementary_grade_period_invalid', 409);
        }
        if ($action === 'publish') {
            $allowed = $submissionStatus === 'published'
                ? ['results_approved', 'results_published']
                : ['results_approved'];
            if (! in_array($period->status, $allowed, true)) {
                $this->fail('The period must have all results approved before publication.', 'supplementary_grade_period_invalid', 409);
            }
        }
    }

    private function assertAssigned(User $actor, SupplementaryExamOffering $offering, bool $locked = false): SupplementaryExamGraderAssignment
    {
        $query = SupplementaryExamGraderAssignment::query()
            ->where('supplementary_exam_offering_id', $offering->getKey())
            ->where('faculty_member_id', $this->facultyId($actor))
            ->where('current_slot', 1);
        if ($locked) {
            $query->lockForUpdate();
        }
        $assignment = $query->first();
        if (! $assignment) {
            $this->fail('You are not the current grader for this offering.', 'supplementary_grader_not_assigned', 403);
        }

        return $assignment;
    }

    private function facultyId(User $actor): int
    {
        $id = (int) FacultyMember::query()
            ->where('employee_id', $actor->employee_id)
            ->where('is_active', true)
            ->value('faculty_member_id');
        if (! $id) {
            $this->fail('The professor faculty identity is inactive.', 'supplementary_grader_identity_invalid', 403);
        }

        return $id;
    }

    private function professor(User $actor, string $permission): void
    {
        if (! $actor->isProfessor() || ! $actor->effectivePermissions()->contains($permission)) {
            $this->fail('An actual professor role and assigned permission are required.', 'supplementary_professor_forbidden', 403);
        }
    }

    private function exam(User $actor, string $permission): void
    {
        if (! $actor->isExamOfficer() || ! $actor->effectivePermissions()->contains($permission)) {
            $this->fail('An actual Exam Officer role and assigned permission are required.', 'supplementary_exam_officer_forbidden', 403);
        }
    }

    private function ready(): void
    {
        if (! Governance::schemaReady()) {
            $this->fail('The supplementary grading schema is not ready.', 'supplementary_grading_schema_not_ready', 503);
        }
    }

    private function periodStatus(SupplementaryExamPeriod $period, User $actor, string $to, string $type): void
    {
        $from = $period->status;
        if ($from === $to) {
            return;
        }
        $period->forceFill(['status' => $to])->save();
        SupplementaryExamPeriodEvent::query()->create([
            'supplementary_exam_period_id' => $period->getKey(),
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'actor_user_id' => $actor->user_id,
            'created_at' => now(),
        ]);
    }

    private function allPeriodOfferingsAt(SupplementaryExamPeriod $period, array $statuses): bool
    {
        $offeringIds = SupplementaryExamOffering::query()
            ->where('supplementary_exam_period_id', $period->getKey())
            ->whereHas('registrations', fn ($query) => $query
                ->where('status', 'registered')
                ->where('current_slot', 1))
            ->pluck('supplementary_exam_offering_id');
        if ($offeringIds->isEmpty()) {
            return false;
        }
        $latest = SupplementaryExamGradeSubmission::query()
            ->whereIn('supplementary_exam_offering_id', $offeringIds)
            ->orderByDesc('submission_version')
            ->get()
            ->groupBy('supplementary_exam_offering_id')
            ->map(fn (Collection $submissions) => $submissions->first());

        return $offeringIds->every(fn ($id) => in_array($latest->get($id)?->status, $statuses, true));
    }

    private function assignmentPayload(?SupplementaryExamGraderAssignment $assignment): ?array
    {
        if (! $assignment) {
            return null;
        }
        $faculty = $assignment->facultyMember;
        $employee = $faculty?->employee;

        return [
            'supplementary_exam_grader_assignment_id' => (int) $assignment->getKey(),
            'faculty_member_id' => (int) $assignment->faculty_member_id,
            'assigned_by_user_id' => (int) $assignment->assigned_by_user_id,
            'assigned_at' => $assignment->assigned_at,
            'faculty_member' => $faculty === null ? null : [
                'faculty_member_id' => (int) $faculty->getKey(),
                'employee_number' => $employee?->employee_number,
                'name' => trim((string) $employee?->first_name.' '.(string) $employee?->last_name),
            ],
        ];
    }

    private function event(
        SupplementaryExamGradeResult $result,
        ?SupplementaryExamGradeSubmission $submission,
        string $type,
        ?string $from,
        string $to,
        User $actor,
        ?string $notes = null,
    ): void {
        SupplementaryExamGradeEvent::query()->create([
            'supplementary_exam_grade_result_id' => $result->getKey(),
            'supplementary_exam_grade_submission_id' => $submission?->getKey(),
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'submission_version' => $result->submission_version,
            'theoretical_mark' => $result->theoretical_mark,
            'actor_user_id' => $actor->user_id,
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }

    private function fail(string $message, string $code, int $status): never
    {
        throw new GradeException($message, status: $status, errorCode: $code);
    }
}
