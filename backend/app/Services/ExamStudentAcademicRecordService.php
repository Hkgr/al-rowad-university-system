<?php

namespace App\Services;

use App\Exceptions\AcademicRequirementConfigurationException;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;

class ExamStudentAcademicRecordService
{
    public const DISPLAY_TIMEZONE = 'Asia/Damascus';

    public function __construct(
        private readonly GradeService $grades,
        private readonly AcademicRequirementService $requirements,
        private readonly GraduationEligibilityService $graduation,
        private readonly UserIdentityService $identities,
    ) {
    }

    /**
     * Build one read-only academic-record snapshot from the canonical grade and
     * requirement services. Expected curriculum-configuration failures affect
     * only the requirements section; infrastructure failures continue to surface.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Student $student, User $actor): array
    {
        $transcript = $this->grades->getTranscript($student);
        $student->loadMissing('studentStatus');

        try {
            $progress = $this->requirements->getStudentRequirementProgress($student);
            $eligibility = $this->graduation->evaluateFromProgress($student, $progress);
            $requirementPayload = [
                'status' => 'available',
                'error_code' => null,
                'progress' => $progress,
                'graduation_eligibility' => $eligibility,
            ];
        } catch (AcademicRequirementConfigurationException $exception) {
            $requirementPayload = [
                'status' => 'unavailable',
                'error_code' => $exception->errorCode,
                'progress' => null,
                'graduation_eligibility' => null,
            ];
        }

        return [
            'transcript' => $transcript,
            'requirements' => $requirementPayload,
            'generation' => [
                'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                'timezone' => self::DISPLAY_TIMEZONE,
                'generated_by' => $this->identities->documentGenerator($actor),
            ],
        ];
    }
}
