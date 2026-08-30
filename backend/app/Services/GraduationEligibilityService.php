<?php

namespace App\Services;

use App\Exceptions\AcademicRequirementConfigurationException;
use App\Exceptions\GraduationEligibilityException;
use App\Models\AcademicRequirementGroup;
use App\Models\Student;

class GraduationEligibilityService
{
    public const BLOCKER_NO_ACADEMIC_PROGRAM = 'no_academic_program';

    public const BLOCKER_ACADEMIC_REQUIREMENTS_INCOMPLETE = 'academic_requirements_incomplete';

    public const BLOCKER_MANDATORY_REQUIREMENTS_INCOMPLETE = 'mandatory_requirements_incomplete';

    public const BLOCKER_ELECTIVE_REQUIREMENTS_INCOMPLETE = 'elective_requirements_incomplete';

    public function __construct(private AcademicRequirementService $requirements)
    {
    }

    public function evaluate(Student $student): array
    {
        if ($student->academic_program_id === null) {
            $progress = $this->requirements->getStudentRequirementProgress($student);

            return $this->ineligibleWithoutProgram($student, $progress['outside_current_curriculum'] ?? []);
        }

        $this->requirements->assertProgramGraduationConfiguration((int) $student->academic_program_id);
        $progress = $this->requirements->getStudentRequirementProgress($student);

        return $this->eligibilityFromProgress($student, $progress);
    }

    /**
     * Evaluate an already-calculated canonical requirement snapshot.
     *
     * This keeps aggregate read endpoints from calculating requirement progress
     * twice while preserving evaluate() as the existing public entry point.
     *
     * @param  array<string, mixed>  $progress
     * @return array<string, mixed>
     */
    public function evaluateFromProgress(Student $student, array $progress): array
    {
        if ($student->academic_program_id === null) {
            return $this->ineligibleWithoutProgram(
                $student,
                $progress['outside_current_curriculum'] ?? []
            );
        }

        $this->requirements->assertProgramGraduationConfiguration((int) $student->academic_program_id);

        return $this->eligibilityFromProgress($student, $progress);
    }

    public function assertEligible(Student $student): array
    {
        $eligibility = $this->evaluate($student);
        if ($eligibility['eligible'] === true) {
            return $eligibility;
        }

        throw new GraduationEligibilityException(
            'The student does not meet academic graduation requirements.',
            [
                'blockers' => $eligibility['blockers'],
                'graduation_eligibility' => $eligibility,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $progress
     * @param  list<array<string, mixed>>  $outside
     */
    private function ineligibleWithoutProgram(Student $student, array $outside): array
    {
        return [
            'student_id' => $student->student_id,
            'academic_program_id' => null,
            'eligible' => false,
            'total_required_hours' => 0,
            'actual_earned_curriculum_hours' => 0,
            'graduation_counted_hours' => 0,
            'remaining_graduation_hours' => 0,
            'mandatory_completed' => false,
            'elective_completed' => false,
            'all_groups_completed' => false,
            'groups' => [],
            'blockers' => [
                ['code' => self::BLOCKER_NO_ACADEMIC_PROGRAM],
            ],
            'outside_current_curriculum' => $outside,
        ];
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    private function eligibilityFromProgress(Student $student, array $progress): array
    {
        $groups = [];
        $graduationCountedHours = 0;
        $mandatoryGroups = [];
        $electiveGroups = [];
        $incompleteGroups = [];

        foreach ($progress['groups'] ?? [] as $group) {
            $requirementType = strtolower((string) ($group['requirement_type'] ?? ''));
            $requiredHours = (int) ($group['required_credit_hours'] ?? 0);
            $earnedHours = (int) ($group['earned_hours'] ?? 0);
            $completed = (bool) ($group['completed'] ?? false);
            $countedHours = $requirementType === AcademicRequirementGroup::TYPE_ELECTIVE
                ? min($earnedHours, $requiredHours)
                : $earnedHours;
            $graduationCountedHours += $countedHours;

            $groupPayload = [
                'requirement_group_id' => $group['requirement_group_id'] ?? null,
                'group_code' => $group['group_code'] ?? null,
                'group_name' => $group['group_name'] ?? null,
                'requirement_scope' => $group['requirement_scope'] ?? null,
                'requirement_type' => $group['requirement_type'] ?? null,
                'required_credit_hours' => $requiredHours,
                'earned_hours' => $earnedHours,
                'graduation_counted_hours' => $countedHours,
                'remaining_hours' => (int) ($group['remaining_hours'] ?? max($requiredHours - $earnedHours, 0)),
                'completed' => $completed,
            ];
            $groups[] = $groupPayload;

            if ($requirementType === AcademicRequirementGroup::TYPE_MANDATORY) {
                $mandatoryGroups[] = $groupPayload;
            } elseif ($requirementType === AcademicRequirementGroup::TYPE_ELECTIVE) {
                $electiveGroups[] = $groupPayload;
            }

            if (! $completed) {
                $incompleteGroups[] = $groupPayload;
            }
        }

        if ($groups === []) {
            throw new AcademicRequirementConfigurationException(
                'Academic requirement configuration is invalid for the current program curriculum.',
                [
                    'academic_program_id' => $student->academic_program_id,
                    'reason' => 'no_active_requirement_groups',
                ]
            );
        }

        $mandatoryCompleted = $mandatoryGroups === []
            || collect($mandatoryGroups)->every(fn (array $group): bool => $group['completed'] === true);
        $electiveCompleted = $electiveGroups === []
            || collect($electiveGroups)->every(fn (array $group): bool => $group['completed'] === true);
        $allGroupsCompleted = $incompleteGroups === [];
        $totalRequiredHours = (int) ($progress['total_required_hours'] ?? 0);
        $actualEarned = (int) ($progress['earned_curriculum_hours'] ?? 0);

        $blockers = [];
        if (! $allGroupsCompleted) {
            $blockers[] = ['code' => self::BLOCKER_ACADEMIC_REQUIREMENTS_INCOMPLETE];
        }
        if (! $mandatoryCompleted) {
            $blockers[] = ['code' => self::BLOCKER_MANDATORY_REQUIREMENTS_INCOMPLETE];
        }
        if (! $electiveCompleted) {
            $blockers[] = ['code' => self::BLOCKER_ELECTIVE_REQUIREMENTS_INCOMPLETE];
        }
        foreach ($incompleteGroups as $group) {
            $blockers[] = [
                'code' => $group['requirement_type'] === AcademicRequirementGroup::TYPE_MANDATORY
                    ? self::BLOCKER_MANDATORY_REQUIREMENTS_INCOMPLETE
                    : self::BLOCKER_ELECTIVE_REQUIREMENTS_INCOMPLETE,
                'requirement_group_id' => $group['requirement_group_id'],
                'requirement_scope' => $group['requirement_scope'],
                'requirement_type' => $group['requirement_type'],
                'required_credit_hours' => $group['required_credit_hours'],
                'earned_hours' => $group['earned_hours'],
                'remaining_hours' => $group['remaining_hours'],
                'completed' => false,
            ];
        }

        return [
            'student_id' => $student->student_id,
            'academic_program_id' => $student->academic_program_id,
            'eligible' => $allGroupsCompleted,
            'total_required_hours' => $totalRequiredHours,
            'actual_earned_curriculum_hours' => $actualEarned,
            'graduation_counted_hours' => $graduationCountedHours,
            'remaining_graduation_hours' => max($totalRequiredHours - $graduationCountedHours, 0),
            'mandatory_completed' => $mandatoryCompleted,
            'elective_completed' => $electiveCompleted,
            'all_groups_completed' => $allGroupsCompleted,
            'groups' => $groups,
            'blockers' => $blockers,
            'outside_current_curriculum' => $progress['outside_current_curriculum'] ?? [],
        ];
    }
}
