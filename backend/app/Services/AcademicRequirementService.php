<?php

namespace App\Services;

use App\Exceptions\AcademicRequirementConfigurationException;
use App\Exceptions\RegistrationException;
use App\Models\AcademicProgram;
use App\Models\AcademicRequirementGroup;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\ProgramCourse;
use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Models\StudentRegistrationRequest;
use App\Models\StudentRegistrationRequestItem;
use App\Support\CourseRequirementClassification;
use Illuminate\Support\Collection;

class AcademicRequirementService
{
    public const CLASSIFICATION_MAPPED = 'mapped';

    public const CLASSIFICATION_OUTSIDE_CURRENT_CURRICULUM = 'outside_current_curriculum';

    public const CLASSIFICATION_REQUIREMENT_MAPPING_MISSING = 'requirement_mapping_missing';

    public const CLASSIFICATION_REQUIREMENT_CONFIGURATION_INVALID = 'requirement_configuration_invalid';

    public const REASON_ELECTIVE_REQUIREMENT_COMPLETED = 'elective_requirement_completed';

    public const REASON_ELECTIVE_REQUIREMENT_FULLY_COMMITTED = 'elective_requirement_fully_committed';

    public const REASON_ELECTIVE_REQUIREMENT_LIMIT_EXCEEDED = 'elective_requirement_limit_exceeded';

    public const REASON_COURSE_OUTSIDE_CURRENT_CURRICULUM = 'course_outside_current_curriculum';

    public function __construct(private GradeService $grades)
    {
    }

    public function getProgramRequirements(AcademicProgram|int $program): Collection
    {
        $programId = $this->programId($program);
        $curriculum = $this->loadValidatedCurriculum($programId);
        $groups = $this->loadActiveRequirementGroups($programId);

        return $groups->map(
            fn (AcademicRequirementGroup $group): array => $this->formatProgramRequirementGroup(
                $group,
                $curriculum->get((int) $group->requirement_group_id, collect())
            )
        )->values();
    }

    public function assertProgramGraduationConfiguration(AcademicProgram|int $program): AcademicProgram
    {
        $programModel = $program instanceof AcademicProgram
            ? $program
            : AcademicProgram::query()->findOrFail($this->programId($program));
        $programId = (int) $programModel->academic_program_id;
        $curriculumByGroup = $this->loadValidatedCurriculum($programId);
        $groups = $this->loadActiveRequirementGroups($programId);

        if ($groups->isEmpty()) {
            throw new AcademicRequirementConfigurationException(
                'Academic requirement configuration is invalid for the current program curriculum.',
                [
                    'academic_program_id' => $programId,
                    'reason' => 'no_active_requirement_groups',
                ]
            );
        }

        $mappedCourseCount = (int) $curriculumByGroup->sum(
            fn (Collection $mappedCourses): int => $mappedCourses->count()
        );
        if ($mappedCourseCount === 0) {
            throw new AcademicRequirementConfigurationException(
                'Academic requirement configuration is invalid for the current program curriculum.',
                [
                    'academic_program_id' => $programId,
                    'reason' => 'no_active_curriculum',
                ]
            );
        }

        $this->assertRequirementGroupsConfiguration($programId, $groups, $curriculumByGroup);

        foreach ($groups as $group) {
            $scope = strtolower((string) $group->requirement_scope);
            if (! in_array($scope, [
                AcademicRequirementGroup::SCOPE_UNIVERSITY,
                AcademicRequirementGroup::SCOPE_COLLEGE,
                AcademicRequirementGroup::SCOPE_DEPARTMENT,
            ], true)) {
                $this->failClosedGroup($programId, $group, 'requirement_scope_invalid');
            }
        }

        $requiredTotal = (int) $groups->sum(
            fn (AcademicRequirementGroup $group): int => (int) $group->required_credit_hours
        );
        if ($programModel->total_credit_hours !== null
            && (int) $programModel->total_credit_hours !== $requiredTotal) {
            throw new AcademicRequirementConfigurationException(
                'Academic requirement configuration is invalid for the current program curriculum.',
                [
                    'academic_program_id' => $programId,
                    'reason' => 'program_total_credit_hours_mismatch',
                    'program_total_credit_hours' => (int) $programModel->total_credit_hours,
                    'requirement_required_hours' => $requiredTotal,
                ]
            );
        }

        return $programModel;
    }

    public function getRequirementGroupMappedCourses(AcademicRequirementGroup|int $group): Collection
    {
        $groupModel = $group instanceof AcademicRequirementGroup
            ? $group
            : AcademicRequirementGroup::query()->findOrFail($group);

        $curriculum = $this->loadValidatedCurriculum((int) $groupModel->academic_program_id);

        return $this->formatMappedCourses(
            $curriculum->get((int) $groupModel->requirement_group_id, collect())
        );
    }

    public function resolveProgramCourseRequirement(AcademicProgram|int $program, Course|int $course): array
    {
        $programId = $this->programId($program);
        $courseId = $this->courseId($course);

        $programCourses = ProgramCourse::query()
            ->where('academic_program_id', $programId)
            ->where('course_id', $courseId)
            ->where('is_active', true)
            ->with(['course', 'requirementMapping.requirementGroup'])
            ->get();

        if ($programCourses->isEmpty()) {
            return [
                'classification' => self::CLASSIFICATION_OUTSIDE_CURRENT_CURRICULUM,
                'academic_program_id' => $programId,
                'course_id' => $courseId,
            ];
        }

        if ($programCourses->count() > 1) {
            return $this->resolutionPayload(
                self::CLASSIFICATION_REQUIREMENT_CONFIGURATION_INVALID,
                $programCourses->first(),
                $programId,
                'duplicate_active_program_course'
            );
        }

        return $this->resolutionPayloadFromClassification(
            $this->classifyProgramCourse($programCourses->first(), $programId),
            $programId
        );
    }

    public function getStudentRequirementProgress(Student $student): array
    {
        $programId = $student->academic_program_id === null ? null : (int) $student->academic_program_id;

        if ($programId === null) {
            $registrations = $this->loadStudentRegistrations($student);

            return $this->emptyProgress(
                $student,
                $registrations->map(
                    fn (StudentCourseRegistration $registration): array => $this->formatOutsideCurriculumRegistration($registration)
                )->values()->all()
            );
        }

        $curriculumByGroup = $this->loadValidatedCurriculum($programId);
        $groups = $this->loadActiveRequirementGroups($programId);
        $curriculumByCourseId = $this->indexCurriculumByCourseId($curriculumByGroup);

        $registrations = $this->loadStudentRegistrations($student);
        $pendingItems = $this->loadOpenRequestItems($student);

        $earnedCourseIds = [];
        $registeredCourseIds = [];
        $pendingCourseIds = [];
        $passedCoursesByGroup = [];
        $registeredCoursesByGroup = [];
        $pendingCoursesByGroup = [];
        $outside = [];

        foreach ($registrations as $registration) {
            $courseId = $this->registrationCourseId($registration);
            if ($courseId === null) {
                $outside[] = $this->formatOutsideCurriculumRegistration($registration);
                continue;
            }

            $classified = $curriculumByCourseId[$courseId] ?? null;
            if ($classified === null) {
                $outside[] = $this->formatOutsideCurriculumRegistration($registration);
                continue;
            }

            $groupId = (int) $classified['requirement_group']->requirement_group_id;
            $course = $classified['program_course']->course;

            if ($this->grades->isOfficiallyPassedAttempt($registration) && ! isset($earnedCourseIds[$courseId])) {
                $earnedCourseIds[$courseId] = true;
                $passedCoursesByGroup[$groupId][] = $this->formatProgressCourse($course, [
                    'student_course_registration_id' => $registration->student_course_registration_id,
                    'course_offering_id' => $registration->course_offering_id,
                    'registration_status' => $registration->registrationStatus?->status_code,
                    'result_status' => 'passed',
                    'final_mark' => $this->officialFinalMark($registration),
                    'requirement_classification' => CourseRequirementClassification::fromProgramCourse($classified['program_course']),
                ]);
            }
        }

        foreach ($registrations as $registration) {
            if ($registration->registrationStatus?->status_code !== StudentCourseRegistration::CURRENT_STATUS) {
                continue;
            }

            $courseId = $this->registrationCourseId($registration);
            if ($courseId === null
                || ! isset($curriculumByCourseId[$courseId])
                || isset($earnedCourseIds[$courseId])
                || isset($registeredCourseIds[$courseId])) {
                continue;
            }

            $classified = $curriculumByCourseId[$courseId];
            $groupId = (int) $classified['requirement_group']->requirement_group_id;
            $registeredCourseIds[$courseId] = true;
            $registeredCoursesByGroup[$groupId][] = $this->formatProgressCourse($classified['program_course']->course, [
                'student_course_registration_id' => $registration->student_course_registration_id,
                'course_offering_id' => $registration->course_offering_id,
                'registration_status' => $registration->registrationStatus?->status_code,
                'result_status' => $this->officialResultStatus($registration),
                'final_mark' => $this->officialFinalMark($registration),
                'requirement_classification' => CourseRequirementClassification::fromProgramCourse($classified['program_course']),
            ]);
        }

        foreach ($pendingItems as $item) {
            $course = $item->courseOffering?->course;
            $courseId = $course?->course_id === null ? null : (int) $course->course_id;
            if ($courseId === null || ! isset($curriculumByCourseId[$courseId])) {
                continue;
            }

            if (isset($earnedCourseIds[$courseId]) || isset($registeredCourseIds[$courseId]) || isset($pendingCourseIds[$courseId])) {
                continue;
            }

            $classified = $curriculumByCourseId[$courseId];
            $groupId = (int) $classified['requirement_group']->requirement_group_id;
            $pendingCourseIds[$courseId] = true;
            $pendingCoursesByGroup[$groupId][] = $this->formatProgressCourse($classified['program_course']->course, [
                'student_registration_request_id' => $item->student_registration_request_id,
                'student_registration_request_item_id' => $item->student_registration_request_item_id,
                'course_offering_id' => $item->course_offering_id,
                'request_status' => $item->request?->status,
                'requirement_classification' => CourseRequirementClassification::fromProgramCourse($classified['program_course']),
            ]);
        }

        $groupPayloads = $groups->map(function (AcademicRequirementGroup $group) use (
            $curriculumByGroup,
            $earnedCourseIds,
            $registeredCourseIds,
            $pendingCourseIds,
            $passedCoursesByGroup,
            $registeredCoursesByGroup,
            $pendingCoursesByGroup
        ): array {
            $mappedCourses = $curriculumByGroup->get((int) $group->requirement_group_id, collect());
            $requiredHours = (int) $group->required_credit_hours;
            $poolHours = $this->poolCreditHours($mappedCourses);
            $earnedHours = $this->hoursForCourseIds($mappedCourses, $earnedCourseIds);
            $registeredHours = $this->hoursForCourseIds($mappedCourses, $registeredCourseIds);
            $pendingHours = $this->hoursForCourseIds($mappedCourses, $pendingCourseIds);
            $committedHours = $earnedHours + $registeredHours + $pendingHours;
            $remainingHours = max($requiredHours - $earnedHours, 0);
            $isMandatory = strtolower((string) $group->requirement_type) === AcademicRequirementGroup::TYPE_MANDATORY;
            $completed = $isMandatory
                ? $this->mandatoryGroupCompleted($mappedCourses, $earnedCourseIds)
                : $earnedHours >= $requiredHours;

            return [
                'requirement_group_id' => $group->requirement_group_id,
                'group_code' => $group->group_code,
                'group_name' => $group->group_name,
                'requirement_scope' => $group->requirement_scope,
                'requirement_type' => $group->requirement_type,
                'required_credit_hours' => $requiredHours,
                'pool_credit_hours' => $poolHours,
                'course_count' => $mappedCourses->count(),
                'earned_hours' => $earnedHours,
                'registered_in_progress_hours' => $registeredHours,
                'pending_request_hours' => $pendingHours,
                'committed_hours' => $committedHours,
                'remaining_hours' => $remainingHours,
                'remaining_commitment_capacity' => max($requiredHours - $committedHours, 0),
                'completed' => $completed,
                'passed_courses' => $passedCoursesByGroup[(int) $group->requirement_group_id] ?? [],
                'registered_courses' => $registeredCoursesByGroup[(int) $group->requirement_group_id] ?? [],
                'pending_courses' => $pendingCoursesByGroup[(int) $group->requirement_group_id] ?? [],
            ];
        })->values();

        $earnedCurriculumHours = (int) $groupPayloads->sum('earned_hours');
        $committedCurriculumHours = (int) $groupPayloads->sum('committed_hours');
        $totalRequiredHours = (int) $groupPayloads->sum('required_credit_hours');

        return [
            'student_id' => $student->student_id,
            'academic_program_id' => $programId,
            'total_required_hours' => $totalRequiredHours,
            'earned_curriculum_hours' => $earnedCurriculumHours,
            'committed_curriculum_hours' => $committedCurriculumHours,
            'remaining_required_hours' => (int) $groupPayloads->sum('remaining_hours'),
            'remaining_commitment_capacity' => (int) $groupPayloads->sum('remaining_commitment_capacity'),
            'groups' => $groupPayloads->all(),
            'outside_current_curriculum' => $outside,
        ];
    }

    public function buildRegistrationCommitmentContext(Student $student): array
    {
        $programId = $student->academic_program_id === null ? null : (int) $student->academic_program_id;
        if ($programId === null) {
            return $this->emptyCommitmentContext($student);
        }

        $curriculumByGroup = $this->loadValidatedCurriculum($programId);
        $groups = $this->loadActiveRequirementGroups($programId);
        $this->assertRequirementGroupsConfiguration($programId, $groups, $curriculumByGroup);
        $curriculumByCourseId = $this->indexCurriculumByCourseId($curriculumByGroup);

        $registrations = $this->loadStudentRegistrations($student);
        $pendingItems = $this->loadOpenRequestItems($student);

        $earnedCourseIds = [];
        $registeredCourseIds = [];
        $pendingCourseIds = [];
        $pendingItemSnapshots = [];

        foreach ($registrations as $registration) {
            $courseId = $this->registrationCourseId($registration);
            if ($courseId === null || ! isset($curriculumByCourseId[$courseId])) {
                continue;
            }

            if ($this->grades->isOfficiallyPassedAttempt($registration)) {
                $earnedCourseIds[$courseId] = true;
            }
        }

        foreach ($registrations as $registration) {
            if ($registration->registrationStatus?->status_code !== StudentCourseRegistration::CURRENT_STATUS) {
                continue;
            }

            $courseId = $this->registrationCourseId($registration);
            if ($courseId === null
                || ! isset($curriculumByCourseId[$courseId])
                || isset($earnedCourseIds[$courseId])
                || isset($registeredCourseIds[$courseId])) {
                continue;
            }

            $registeredCourseIds[$courseId] = true;
        }

        foreach ($pendingItems as $item) {
            $course = $item->courseOffering?->course;
            $courseId = $course?->course_id === null ? null : (int) $course->course_id;
            if ($courseId === null || ! isset($curriculumByCourseId[$courseId])) {
                continue;
            }

            $classified = $curriculumByCourseId[$courseId];
            $group = $classified['requirement_group'];
            $hours = (int) ($classified['program_course']->course?->credit_hours ?? $course->credit_hours ?? 0);
            $pendingItemSnapshots[] = [
                'request_id' => (int) $item->student_registration_request_id,
                'course_id' => $courseId,
                'group_id' => (int) $group->requirement_group_id,
                'credit_hours' => $hours,
                'course_offering_id' => (int) $item->course_offering_id,
            ];

            if (isset($earnedCourseIds[$courseId]) || isset($registeredCourseIds[$courseId]) || isset($pendingCourseIds[$courseId])) {
                continue;
            }

            $pendingCourseIds[$courseId] = true;
        }

        $groupStates = [];
        foreach ($groups as $group) {
            $mappedCourses = $curriculumByGroup->get((int) $group->requirement_group_id, collect());
            $requiredHours = (int) $group->required_credit_hours;
            $earnedHours = $this->hoursForCourseIds($mappedCourses, $earnedCourseIds);
            $registeredHours = $this->hoursForCourseIds($mappedCourses, $registeredCourseIds);
            $pendingHours = $this->hoursForCourseIds($mappedCourses, $pendingCourseIds);
            $committedHours = $earnedHours + $registeredHours + $pendingHours;
            $groupId = (int) $group->requirement_group_id;
            $groupStates[$groupId] = [
                'requirement_group_id' => $groupId,
                'group_code' => $group->group_code,
                'group_name' => $group->group_name,
                'requirement_scope' => $group->requirement_scope,
                'requirement_type' => $group->requirement_type,
                'required_credit_hours' => $requiredHours,
                'pool_credit_hours' => $this->poolCreditHours($mappedCourses),
                'earned_hours' => $earnedHours,
                'registered_in_progress_hours' => $registeredHours,
                'pending_request_hours' => $pendingHours,
                'committed_hours' => $committedHours,
                'remaining_commitment_capacity' => max($requiredHours - $committedHours, 0),
            ];
        }

        return [
            'student_id' => $student->student_id,
            'academic_program_id' => $programId,
            'curriculum_by_course_id' => $curriculumByCourseId,
            'groups' => $groupStates,
            'earned_course_ids' => $earnedCourseIds,
            'registered_course_ids' => $registeredCourseIds,
            'pending_course_ids' => $pendingCourseIds,
            'pending_items' => $pendingItemSnapshots,
        ];
    }

    public function evaluateRegistrationCandidate(
        Student $student,
        Course|CourseOffering|int $candidate,
        ?array $context = null
    ): array {
        $context ??= $this->buildRegistrationCommitmentContext($student);
        $courseId = $this->candidateCourseId($candidate);

        if ($courseId === null || ! isset($context['curriculum_by_course_id'][$courseId])) {
            return $this->candidateEvaluationPayload(
                allowed: false,
                classification: self::CLASSIFICATION_OUTSIDE_CURRENT_CURRICULUM,
                courseId: $courseId,
                candidateHours: $this->candidateCreditHours($candidate, null),
                reason: self::REASON_COURSE_OUTSIDE_CURRENT_CURRICULUM,
                context: $context
            );
        }

        $classified = $context['curriculum_by_course_id'][$courseId];
        if (($classified['classification'] ?? self::CLASSIFICATION_MAPPED) !== self::CLASSIFICATION_MAPPED) {
            $this->failClosed(
                $classified['program_course'],
                (int) $context['academic_program_id'],
                (string) ($classified['reason'] ?? $classified['classification'])
            );
        }

        $programCourse = $classified['program_course'];
        $group = $classified['requirement_group'];
        $groupId = (int) $group->requirement_group_id;
        $groupState = $context['groups'][$groupId] ?? null;
        $candidateHours = (int) ($programCourse->course?->credit_hours ?? $this->candidateCreditHours($candidate, $programCourse->course));
        $alreadyCommitted = isset($context['earned_course_ids'][$courseId])
            || isset($context['registered_course_ids'][$courseId])
            || isset($context['pending_course_ids'][$courseId]);
        $delta = $alreadyCommitted ? 0 : $candidateHours;
        $requiredHours = (int) ($groupState['required_credit_hours'] ?? $group->required_credit_hours);
        $earnedHours = (int) ($groupState['earned_hours'] ?? 0);
        $registeredHours = (int) ($groupState['registered_in_progress_hours'] ?? 0);
        $pendingHours = (int) ($groupState['pending_request_hours'] ?? 0);
        $committedHours = (int) ($groupState['committed_hours'] ?? ($earnedHours + $registeredHours + $pendingHours));
        $prospective = $committedHours + $delta;
        $requirementType = strtolower((string) ($groupState['requirement_type'] ?? $group->requirement_type));
        $reason = null;
        $allowed = true;

        if ($requirementType === AcademicRequirementGroup::TYPE_ELECTIVE) {
            if ($earnedHours >= $requiredHours) {
                $allowed = false;
                $reason = self::REASON_ELECTIVE_REQUIREMENT_COMPLETED;
            } elseif ($delta > 0) {
                if ($committedHours >= $requiredHours) {
                    $allowed = false;
                    $reason = self::REASON_ELECTIVE_REQUIREMENT_FULLY_COMMITTED;
                } elseif ($prospective > $requiredHours) {
                    $allowed = false;
                    $reason = self::REASON_ELECTIVE_REQUIREMENT_LIMIT_EXCEEDED;
                }
            }
        }

        return $this->candidateEvaluationPayload(
            allowed: $allowed,
            classification: self::CLASSIFICATION_MAPPED,
            courseId: $courseId,
            candidateHours: $candidateHours,
            reason: $reason,
            context: $context,
            programCourse: $programCourse,
            groupState: $groupState,
            group: $group,
            delta: $delta,
            committedHours: $committedHours,
            prospective: $prospective
        );
    }

    public function evaluateRegistrationCandidates(Student $student, Collection $candidates, ?array $context = null): Collection
    {
        $context ??= $this->buildRegistrationCommitmentContext($student);

        return $candidates->map(
            fn (Course|CourseOffering|int $candidate): array => $this->evaluateRegistrationCandidate($student, $candidate, $context)
        )->values();
    }

    public function validateRegistrationRequestCommitment(Student $student, StudentRegistrationRequest $request, ?array $context = null): array
    {
        $context ??= $this->buildRegistrationCommitmentContext($student);
        $running = $this->contextWithoutRequest($context, $request);
        $failures = [];

        $request->loadMissing('items.courseOffering.course');
        foreach ($request->items->sortBy('student_registration_request_item_id') as $item) {
            $offering = $item->courseOffering;
            if ($offering === null) {
                continue;
            }

            $evaluation = $this->evaluateRegistrationCandidate($student, $offering, $running);
            if (! $evaluation['allowed']) {
                $failures[] = [
                    'course_offering_id' => (int) $offering->course_offering_id,
                    'reason' => $evaluation['reason'],
                    'requirement_group_id' => $evaluation['requirement_group_id'] ?? null,
                ];
                continue;
            }

            $running = $this->contextWithCandidate($running, $evaluation);
        }

        return $failures;
    }

    public function assertRegistrationCandidateAllowed(
        Student $student,
        CourseOffering $offering,
        ?array $context = null
    ): array {
        $evaluation = $this->evaluateRegistrationCandidate($student, $offering, $context);
        if ($evaluation['allowed']) {
            return $evaluation;
        }

        throw new RegistrationException(
            $this->registrationDenialMessage((string) $evaluation['reason']),
            ['course_offering_id' => [(string) $evaluation['reason']]],
            422,
            (string) $evaluation['reason']
        );
    }

    /**
     * @return Collection<int, Collection<int, ProgramCourse>>
     */
    private function loadValidatedCurriculum(int $programId): Collection
    {
        $programCourses = ProgramCourse::query()
            ->where('academic_program_id', $programId)
            ->where('is_active', true)
            ->with(['course', 'requirementMapping.requirementGroup'])
            ->get();

        $byCourseId = [];
        $byGroupId = [];

        foreach ($programCourses as $programCourse) {
            $courseId = (int) $programCourse->course_id;
            if (isset($byCourseId[$courseId])) {
                $this->failClosed($programCourse, $programId, 'duplicate_active_program_course');
            }

            $classified = $this->classifyProgramCourse($programCourse, $programId);
            if ($classified['classification'] !== self::CLASSIFICATION_MAPPED) {
                $this->failClosed(
                    $programCourse,
                    $programId,
                    (string) ($classified['reason'] ?? $classified['classification'])
                );
            }

            $groupId = (int) $classified['requirement_group']->requirement_group_id;
            $byCourseId[$courseId] = $classified;
            $byGroupId[$groupId] ??= collect();
            $byGroupId[$groupId]->push($programCourse);
        }

        return collect($byGroupId);
    }

    /**
     * @param  Collection<int, Collection<int, ProgramCourse>>  $curriculumByGroup
     * @return array<int, array{classification: string, program_course: ProgramCourse, requirement_group: AcademicRequirementGroup}>
     */
    private function indexCurriculumByCourseId(Collection $curriculumByGroup): array
    {
        $byCourseId = [];

        foreach ($curriculumByGroup as $programCourses) {
            foreach ($programCourses as $programCourse) {
                $classified = $this->classifyProgramCourse(
                    $programCourse,
                    (int) $programCourse->academic_program_id
                );
                $byCourseId[(int) $programCourse->course_id] = $classified;
            }
        }

        return $byCourseId;
    }

    private function loadActiveRequirementGroups(int $programId): Collection
    {
        return AcademicRequirementGroup::query()
            ->where('academic_program_id', $programId)
            ->where('is_active', true)
            ->orderBy('requirement_scope')
            ->orderBy('requirement_type')
            ->orderBy('group_code')
            ->get();
    }

    private function loadStudentRegistrations(Student $student): Collection
    {
        return StudentCourseRegistration::query()
            ->where('student_id', $student->student_id)
            ->with([
                'courseOffering.course',
                'courseOffering.gradeApprovals.approvalStatus',
                'studentCourseResult.resultStatus',
                'resultStatus',
                'registrationStatus',
            ])
            ->orderBy('student_course_registration_id')
            ->get();
    }

    private function loadOpenRequestItems(Student $student): Collection
    {
        return StudentRegistrationRequestItem::query()
            ->whereHas('request', function ($query) use ($student): void {
                $query->where('student_id', $student->student_id)
                    ->whereIn('status', StudentRegistrationRequest::OPEN_STATUSES);
            })
            ->with(['courseOffering.course', 'request'])
            ->orderBy('student_registration_request_item_id')
            ->get();
    }

    private function classifyProgramCourse(ProgramCourse $programCourse, int $programId): array
    {
        $mapping = $programCourse->requirementMapping;
        if ($mapping === null) {
            return [
                'classification' => self::CLASSIFICATION_REQUIREMENT_MAPPING_MISSING,
                'reason' => 'requirement_mapping_missing',
                'program_course' => $programCourse,
            ];
        }

        $group = $mapping->requirementGroup;
        if ($group === null) {
            return [
                'classification' => self::CLASSIFICATION_REQUIREMENT_CONFIGURATION_INVALID,
                'reason' => 'requirement_group_missing',
                'program_course' => $programCourse,
            ];
        }

        if ((int) $group->academic_program_id !== $programId) {
            return [
                'classification' => self::CLASSIFICATION_REQUIREMENT_CONFIGURATION_INVALID,
                'reason' => 'requirement_group_program_mismatch',
                'program_course' => $programCourse,
                'requirement_group' => $group,
            ];
        }

        if (! $group->is_active) {
            return [
                'classification' => self::CLASSIFICATION_REQUIREMENT_CONFIGURATION_INVALID,
                'reason' => 'requirement_group_inactive',
                'program_course' => $programCourse,
                'requirement_group' => $group,
            ];
        }

        if (strtolower((string) $programCourse->course_type) !== strtolower((string) $group->requirement_type)) {
            return [
                'classification' => self::CLASSIFICATION_REQUIREMENT_CONFIGURATION_INVALID,
                'reason' => 'course_type_requirement_type_mismatch',
                'program_course' => $programCourse,
                'requirement_group' => $group,
            ];
        }

        return [
            'classification' => self::CLASSIFICATION_MAPPED,
            'program_course' => $programCourse,
            'requirement_group' => $group,
            'reason' => null,
        ];
    }

    private function resolutionPayloadFromClassification(array $classified, int $programId): array
    {
        return $this->resolutionPayload(
            $classified['classification'],
            $classified['program_course'],
            $programId,
            $classified['reason'] ?? null,
            $classified['requirement_group'] ?? null
        );
    }

    private function resolutionPayload(
        string $classification,
        ProgramCourse $programCourse,
        int $programId,
        ?string $reason = null,
        ?AcademicRequirementGroup $group = null
    ): array {
        $payload = [
            'classification' => $classification,
            'academic_program_id' => $programId,
            'program_course_id' => $programCourse->program_course_id,
            'course_id' => $programCourse->course_id,
        ];

        if ($reason !== null) {
            $payload['reason'] = $reason;
        }

        if ($classification === self::CLASSIFICATION_MAPPED && $group !== null) {
            $payload['requirement_group_id'] = $group->requirement_group_id;
            $payload['group_code'] = $group->group_code;
            $payload['group_name'] = $group->group_name;
            $payload['requirement_scope'] = $group->requirement_scope;
            $payload['requirement_type'] = $group->requirement_type;
            $payload['required_credit_hours'] = (int) $group->required_credit_hours;
        }

        return $payload;
    }

    private function failClosed(ProgramCourse $programCourse, int $programId, string $reason): never
    {
        throw new AcademicRequirementConfigurationException(
            'Academic requirement configuration is invalid for the current program curriculum.',
            [
                'academic_program_id' => $programId,
                'program_course_id' => $programCourse->program_course_id,
                'course_id' => $programCourse->course_id,
                'reason' => $reason,
            ]
        );
    }

    /**
     * @param  Collection<int, ProgramCourse>  $mappedCourses
     */
    private function formatProgramRequirementGroup(AcademicRequirementGroup $group, Collection $mappedCourses): array
    {
        return [
            'requirement_group_id' => $group->requirement_group_id,
            'group_code' => $group->group_code,
            'group_name' => $group->group_name,
            'requirement_scope' => $group->requirement_scope,
            'requirement_type' => $group->requirement_type,
            'required_credit_hours' => (int) $group->required_credit_hours,
            'pool_credit_hours' => $this->poolCreditHours($mappedCourses),
            'course_count' => $mappedCourses->count(),
            'courses' => $this->formatMappedCourses($mappedCourses)->all(),
        ];
    }

    /**
     * @param  Collection<int, ProgramCourse>  $mappedCourses
     */
    private function formatMappedCourses(Collection $mappedCourses): Collection
    {
        return $mappedCourses->map(function (ProgramCourse $programCourse): array {
            $course = $programCourse->course;

            return [
                'program_course_id' => $programCourse->program_course_id,
                'course_id' => $programCourse->course_id,
                'course_code' => $course?->course_code,
                'course_name' => $course?->course_name,
                'credit_hours' => (int) ($course?->credit_hours ?? 0),
                'course_type' => $programCourse->course_type,
                'requirement_classification' => CourseRequirementClassification::fromProgramCourse($programCourse),
            ];
        })->values();
    }

    /**
     * @param  Collection<int, ProgramCourse>  $mappedCourses
     */
    private function poolCreditHours(Collection $mappedCourses): int
    {
        return (int) $mappedCourses->sum(
            fn (ProgramCourse $programCourse): int => (int) ($programCourse->course?->credit_hours ?? 0)
        );
    }

    /**
     * @param  Collection<int, ProgramCourse>  $mappedCourses
     * @param  array<int, true>  $courseIds
     */
    private function hoursForCourseIds(Collection $mappedCourses, array $courseIds): int
    {
        return (int) $mappedCourses
            ->filter(fn (ProgramCourse $programCourse): bool => isset($courseIds[(int) $programCourse->course_id]))
            ->sum(fn (ProgramCourse $programCourse): int => (int) ($programCourse->course?->credit_hours ?? 0));
    }

    /**
     * @param  Collection<int, ProgramCourse>  $mappedCourses
     * @param  array<int, true>  $earnedCourseIds
     */
    private function mandatoryGroupCompleted(Collection $mappedCourses, array $earnedCourseIds): bool
    {
        if ($mappedCourses->isEmpty()) {
            return false;
        }

        return $mappedCourses->every(
            fn (ProgramCourse $programCourse): bool => isset($earnedCourseIds[(int) $programCourse->course_id])
        );
    }

    private function formatProgressCourse(?Course $course, array $extra = []): array
    {
        return array_merge([
            'course_id' => $course?->course_id,
            'course_code' => $course?->course_code,
            'course_name' => $course?->course_name,
            'credit_hours' => (int) ($course?->credit_hours ?? 0),
        ], $extra);
    }

    private function formatOutsideCurriculumRegistration(StudentCourseRegistration $registration): array
    {
        $course = $registration->courseOffering?->course;

        return [
            'student_course_registration_id' => $registration->student_course_registration_id,
            'course_offering_id' => $registration->course_offering_id,
            'course_id' => $course?->course_id,
            'course_code' => $course?->course_code,
            'course_name' => $course?->course_name,
            'credit_hours' => (int) ($course?->credit_hours ?? 0),
            'registration_status' => $registration->registrationStatus?->status_code,
            'result_status' => $this->officialResultStatus($registration),
            'final_mark' => $this->officialFinalMark($registration),
            'classification' => self::CLASSIFICATION_OUTSIDE_CURRENT_CURRICULUM,
            'requirement_classification' => CourseRequirementClassification::empty(
                $registration->student?->academic_program_id === null ? null : (int) $registration->student->academic_program_id,
                CourseRequirementClassification::STATUS_OUTSIDE_CURRENT_CURRICULUM
            ),
        ];
    }

    private function officialResultStatus(StudentCourseRegistration $registration): ?string
    {
        return $this->grades->officialAttemptResultStatus($registration);
    }

    private function officialFinalMark(StudentCourseRegistration $registration): ?float
    {
        if (! $this->grades->isOfficiallyVisibleAttempt($registration)) {
            return null;
        }

        $mark = $registration->studentCourseResult?->final_mark;

        return $mark !== null ? (float) $mark : null;
    }

    private function registrationCourseId(StudentCourseRegistration $registration): ?int
    {
        $courseId = $registration->courseOffering?->course_id ?? $registration->courseOffering?->course?->course_id;

        return $courseId === null ? null : (int) $courseId;
    }

    private function emptyProgress(Student $student, array $outside): array
    {
        return [
            'student_id' => $student->student_id,
            'academic_program_id' => $student->academic_program_id,
            'total_required_hours' => 0,
            'earned_curriculum_hours' => 0,
            'committed_curriculum_hours' => 0,
            'remaining_required_hours' => 0,
            'remaining_commitment_capacity' => 0,
            'groups' => [],
            'outside_current_curriculum' => $outside,
        ];
    }

    private function programId(AcademicProgram|int $program): int
    {
        return $program instanceof AcademicProgram
            ? (int) $program->academic_program_id
            : $program;
    }

    private function courseId(Course|int $course): int
    {
        return $course instanceof Course
            ? (int) $course->course_id
            : $course;
    }

    private function emptyCommitmentContext(Student $student): array
    {
        return [
            'student_id' => $student->student_id,
            'academic_program_id' => $student->academic_program_id,
            'curriculum_by_course_id' => [],
            'groups' => [],
            'earned_course_ids' => [],
            'registered_course_ids' => [],
            'pending_course_ids' => [],
            'pending_items' => [],
        ];
    }

    /**
     * @param  Collection<int, AcademicRequirementGroup>  $groups
     * @param  Collection<int, Collection<int, ProgramCourse>>  $curriculumByGroup
     */
    private function assertRequirementGroupsConfiguration(
        int $programId,
        Collection $groups,
        Collection $curriculumByGroup
    ): void {
        foreach ($groups as $group) {
            $mappedCourses = $curriculumByGroup->get((int) $group->requirement_group_id, collect());
            $requiredHours = (int) $group->required_credit_hours;
            $poolHours = $this->poolCreditHours($mappedCourses);
            $type = strtolower((string) $group->requirement_type);

            if ($requiredHours < 0) {
                $this->failClosedGroup($programId, $group, 'required_credit_hours_negative');
            }

            if ($type === AcademicRequirementGroup::TYPE_ELECTIVE) {
                if ($requiredHours > $poolHours) {
                    $this->failClosedGroup($programId, $group, 'elective_required_hours_exceed_pool');
                }

                continue;
            }

            if ($type === AcademicRequirementGroup::TYPE_MANDATORY) {
                if ($requiredHours !== $poolHours) {
                    $this->failClosedGroup($programId, $group, 'mandatory_required_hours_mismatch_pool');
                }

                continue;
            }

            $this->failClosedGroup($programId, $group, 'requirement_type_invalid');
        }
    }

    private function failClosedGroup(int $programId, AcademicRequirementGroup $group, string $reason): never
    {
        throw new AcademicRequirementConfigurationException(
            'Academic requirement configuration is invalid for the current program curriculum.',
            [
                'academic_program_id' => $programId,
                'requirement_group_id' => $group->requirement_group_id,
                'reason' => $reason,
            ]
        );
    }

    private function candidateCourseId(Course|CourseOffering|int $candidate): ?int
    {
        if ($candidate instanceof CourseOffering) {
            $courseId = $candidate->course_id ?? $candidate->course?->course_id;

            return $courseId === null ? null : (int) $courseId;
        }

        if ($candidate instanceof Course) {
            return (int) $candidate->course_id;
        }

        return $candidate;
    }

    private function candidateCreditHours(Course|CourseOffering|int $candidate, ?Course $fallback): int
    {
        if ($candidate instanceof CourseOffering) {
            return (int) ($candidate->course?->credit_hours ?? $fallback?->credit_hours ?? 0);
        }

        if ($candidate instanceof Course) {
            return (int) ($candidate->credit_hours ?? $fallback?->credit_hours ?? 0);
        }

        return (int) ($fallback?->credit_hours ?? 0);
    }

    private function candidateEvaluationPayload(
        bool $allowed,
        string $classification,
        ?int $courseId,
        int $candidateHours,
        ?string $reason,
        array $context,
        ?ProgramCourse $programCourse = null,
        ?array $groupState = null,
        ?AcademicRequirementGroup $group = null,
        int $delta = 0,
        int $committedHours = 0,
        int $prospective = 0
    ): array {
        $requiredHours = (int) ($groupState['required_credit_hours'] ?? $group?->required_credit_hours ?? 0);
        $earnedHours = (int) ($groupState['earned_hours'] ?? 0);
        $registeredHours = (int) ($groupState['registered_in_progress_hours'] ?? 0);
        $pendingHours = (int) ($groupState['pending_request_hours'] ?? 0);

        return [
            'allowed' => $allowed,
            'classification' => $classification,
            'course_id' => $courseId,
            'program_course_id' => $programCourse?->program_course_id,
            'requirement_group_id' => $groupState['requirement_group_id'] ?? $group?->requirement_group_id,
            'requirement_scope' => $groupState['requirement_scope'] ?? $group?->requirement_scope,
            'requirement_type' => $groupState['requirement_type'] ?? $group?->requirement_type,
            'required_credit_hours' => $requiredHours,
            'earned_hours' => $earnedHours,
            'registered_in_progress_hours' => $registeredHours,
            'pending_request_hours' => $pendingHours,
            'committed_hours' => $committedHours,
            'candidate_credit_hours' => $candidateHours,
            'candidate_commitment_delta' => $delta,
            'prospective_committed_hours' => $prospective,
            'remaining_commitment_capacity' => max($requiredHours - $committedHours, 0),
            'reason' => $reason,
            'academic_program_id' => $context['academic_program_id'] ?? null,
        ];
    }

    private function contextWithoutRequest(array $context, StudentRegistrationRequest $request): array
    {
        $requestId = (int) $request->student_registration_request_id;
        $seenCourseIds = [];

        foreach ($context['pending_items'] ?? [] as $item) {
            if ((int) $item['request_id'] !== $requestId) {
                continue;
            }

            $courseId = (int) $item['course_id'];
            if (isset($seenCourseIds[$courseId])) {
                continue;
            }
            $seenCourseIds[$courseId] = true;

            if (! isset($context['pending_course_ids'][$courseId])) {
                continue;
            }
            if (isset($context['earned_course_ids'][$courseId]) || isset($context['registered_course_ids'][$courseId])) {
                continue;
            }

            unset($context['pending_course_ids'][$courseId]);
            $groupId = (int) $item['group_id'];
            $hours = (int) $item['credit_hours'];
            if (! isset($context['groups'][$groupId])) {
                continue;
            }

            $context['groups'][$groupId]['pending_request_hours'] = max(
                (int) $context['groups'][$groupId]['pending_request_hours'] - $hours,
                0
            );
            $context['groups'][$groupId]['committed_hours'] = (int) $context['groups'][$groupId]['earned_hours']
                + (int) $context['groups'][$groupId]['registered_in_progress_hours']
                + (int) $context['groups'][$groupId]['pending_request_hours'];
            $context['groups'][$groupId]['remaining_commitment_capacity'] = max(
                (int) $context['groups'][$groupId]['required_credit_hours']
                - (int) $context['groups'][$groupId]['committed_hours'],
                0
            );
        }

        return $context;
    }

    private function contextWithCandidate(array $context, array $evaluation): array
    {
        $delta = (int) ($evaluation['candidate_commitment_delta'] ?? 0);
        $courseId = $evaluation['course_id'] === null ? null : (int) $evaluation['course_id'];
        $groupId = $evaluation['requirement_group_id'] === null ? null : (int) $evaluation['requirement_group_id'];

        if ($delta <= 0 || $courseId === null || $groupId === null) {
            if ($courseId !== null) {
                $context['pending_course_ids'][$courseId] = true;
            }

            return $context;
        }

        $context['pending_course_ids'][$courseId] = true;
        if (! isset($context['groups'][$groupId])) {
            return $context;
        }

        $context['groups'][$groupId]['pending_request_hours'] = (int) $context['groups'][$groupId]['pending_request_hours'] + $delta;
        $context['groups'][$groupId]['committed_hours'] = (int) $context['groups'][$groupId]['committed_hours'] + $delta;
        $context['groups'][$groupId]['remaining_commitment_capacity'] = max(
            (int) $context['groups'][$groupId]['required_credit_hours']
            - (int) $context['groups'][$groupId]['committed_hours'],
            0
        );

        return $context;
    }

    private function registrationDenialMessage(string $reason): string
    {
        return match ($reason) {
            self::REASON_ELECTIVE_REQUIREMENT_COMPLETED => 'The elective requirement for this group is already completed.',
            self::REASON_ELECTIVE_REQUIREMENT_FULLY_COMMITTED => 'The elective requirement for this group is already fully committed.',
            self::REASON_ELECTIVE_REQUIREMENT_LIMIT_EXCEEDED => 'Adding this course would exceed the elective requirement limit.',
            self::REASON_COURSE_OUTSIDE_CURRENT_CURRICULUM => 'The selected course is not part of the student current program curriculum.',
            default => 'This course cannot be registered under the current academic requirement rules.',
        };
    }

}
