<?php

namespace App\Services;

use App\Exceptions\AcademicRequirementConfigurationException;
use App\Models\AcademicProgram;
use App\Models\AcademicRequirementGroup;
use App\Models\Course;
use App\Models\ProgramCourse;
use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Models\StudentRegistrationRequest;
use App\Models\StudentRegistrationRequestItem;
use Illuminate\Support\Collection;

class AcademicRequirementService
{
    public const CLASSIFICATION_MAPPED = 'mapped';

    public const CLASSIFICATION_OUTSIDE_CURRENT_CURRICULUM = 'outside_current_curriculum';

    public const CLASSIFICATION_REQUIREMENT_MAPPING_MISSING = 'requirement_mapping_missing';

    public const CLASSIFICATION_REQUIREMENT_CONFIGURATION_INVALID = 'requirement_configuration_invalid';

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
}
