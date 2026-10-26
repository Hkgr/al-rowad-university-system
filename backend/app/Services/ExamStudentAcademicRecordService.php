<?php

namespace App\Services;

use App\Exceptions\AcademicRequirementConfigurationException;
use App\Models\Student;
use App\Models\StudentAcademicTerm;
use App\Models\User;
use App\Support\HistoricalExamSchema;
use App\Support\OfficialGradeScale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ExamStudentAcademicRecordService
{
    public const DISPLAY_TIMEZONE = 'Asia/Damascus';

    public function __construct(
        private readonly GradeService $grades,
        private readonly AcademicRequirementService $requirements,
        private readonly GraduationEligibilityService $graduation,
        private readonly UserIdentityService $identities,
    ) {}

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

    /**
     * Presentation-only additions for the exam office's approved paper form.
     * Keep the shared snapshot and GradeService's rules unchanged.
     */
    public function examExportSnapshot(Student $student, User $actor): array
    {
        // Use the canonical non-locking epoch fence for the entire historical
        // export, so course rows and per-term metrics cannot span two revisions.
        if (HistoricalExamSchema::installed() && DB::connection()->transactionLevel() === 0) {
            return app(AcademicCatalogTransaction::class)->snapshot(
                fn (): array => $this->examExportSnapshot($student, $actor)
            );
        }

        $payload = $this->snapshot($student, $actor);
        $history = StudentAcademicTerm::query()
            ->where('student_id', $student->getKey())
            ->with('academicLevel')
            ->get()
            ->keyBy(fn (StudentAcademicTerm $term): string => $term->academic_year_id.'-'.$term->semester_id);

        $terms = [];
        foreach ($payload['transcript']['terms'] ?? [] as $term) {
            $yearId = (int) ($term['academic_year']['academic_year_id'] ?? 0);
            $semesterId = (int) ($term['semester']['semester_id'] ?? 0);
            $context = $history->get($yearId.'-'.$semesterId);
            // Recompute the read-only official metrics, never use saved GPA
            // columns or repeat the final CGPA under earlier semesters.
            $metrics = $yearId && $semesterId
                ? $this->grades->officialTermMetrics($student, $yearId, $semesterId)
                : [];
            $terms[] = array_merge($term, [
                'term_gpa' => $metrics['term_gpa'] ?? null,
                'cumulative_gpa' => $metrics['cumulative_gpa'] ?? null,
                'historical_academic_level' => $context?->academicLevel?->level_name,
            ]);
        }

        $payload['exam_export'] = [
            'template' => 'exam-board-approved',
            'identity' => [
                'father_name' => $student->father_name,
                'mother_name' => $student->mother_name,
                'nationality' => $student->nationality,
                'gender' => $student->gender,
                'date_of_birth' => $student->date_of_birth?->format('Y-m-d'),
                // Neither address nor nationality is evidence of birthplace.
                'place_of_birth' => null,
            ],
            'terms' => $terms,
            'grade_scale' => [
                'bands' => OfficialGradeScale::BANDS,
                'failure' => ['letter' => 'F', 'points' => 0.0, 'minimum' => 0],
            ],
        ];

        return $payload;
    }
}
