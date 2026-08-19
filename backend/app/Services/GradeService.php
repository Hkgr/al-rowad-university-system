<?php

namespace App\Services;

use App\Exceptions\GradeException;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\GradeAuditLog;
use App\Models\GradeComponent;
use App\Models\GradeApproval;
use App\Models\GradePartApproval;
use App\Models\GradingPolicy;
use App\Models\ResultStatus;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Models\StudentCourseResult;
use App\Models\StudentGradeComponent;
use App\Support\CourseRequirementClassification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GradeService
{
    private const EXCLUDED_RESULT_STATUSES = ['incomplete', 'deprived', 'withdrawn'];

    private ?GradingPolicy $defaultPolicy = null;

    public function getGradeSheet(int $courseOfferingId, bool $includeInactive = false): array
    {
        $offering = CourseOffering::query()
            ->with(['course', 'academicYear', 'semester'])
            ->findOrFail($courseOfferingId);
        CourseRequirementClassification::hydrateOfferings([$offering]);

        $registrationsQuery = $offering->studentCourseRegistrations()
            ->with([
                'student',
                'registrationStatus',
                'studentCourseResult.resultStatus',
                'courseOffering.course',
            ]);

        if (! $includeInactive) {
            $registrationsQuery->currentOrHistoricalWithResult();
        }

        $registrations = $registrationsQuery
            ->orderBy('student_course_registration_id')
            ->get();
        CourseRequirementClassification::hydrateOfferings(
            $registrations->map(fn (StudentCourseRegistration $registration) => $registration->courseOffering)->push($offering)
        );

        $approval = GradeApproval::query()
            ->where('course_offering_id', $courseOfferingId)
            ->with('approvalStatus')
            ->orderByDesc('grade_approval_id')
            ->first();
        $workflowEditable = $approval === null || $approval->allowsGradeEditing();

        return [
            'course_offering_id' => $offering->course_offering_id,
            'course_code' => $offering->course?->course_code,
            'course_name' => $offering->course?->course_name,
            'requirement_classification' => CourseRequirementClassification::forOffering($offering),
            'academic_year' => $this->compactAcademicYear($offering->academicYear),
            'semester' => $this->compactSemester($offering->semester),
            'students' => $registrations
                ->map(fn (StudentCourseRegistration $registration) => $this->formatGradeSheetRow($registration, $workflowEditable))
                ->values()
                ->all(),
        ];
    }

    public function getResultsSummary(int $courseOfferingId): array
    {
        $offering = CourseOffering::query()->findOrFail($courseOfferingId);

        $registrations = $offering->studentCourseRegistrations()
            ->with(['studentCourseResult.resultStatus', 'registrationStatus'])
            ->currentOrHistoricalWithResult()
            ->get();

        $withResults = $registrations->filter(fn (StudentCourseRegistration $registration) => $registration->studentCourseResult !== null);
        $finalMarks = $withResults
            ->map(fn (StudentCourseRegistration $registration) => (float) $registration->studentCourseResult->final_mark)
            ->values();

        $statusCounts = [
            'passed' => 0,
            'failed' => 0,
            'incomplete' => 0,
            'deprived' => 0,
            'withdrawn' => 0,
        ];

        foreach ($withResults as $registration) {
            $statusCode = $this->resolveEffectiveResultStatusCode($registration);
            if (array_key_exists($statusCode, $statusCounts)) {
                $statusCounts[$statusCode]++;
            }
        }

        $passedCount = $statusCounts['passed'];
        $studentsWithResults = $withResults->count();

        return [
            'course_offering_id' => $offering->course_offering_id,
            'total_registered_students' => $registrations->count(),
            'total_students_with_results' => $studentsWithResults,
            'passed_count' => $passedCount,
            'failed_count' => $statusCounts['failed'],
            'incomplete_count' => $statusCounts['incomplete'],
            'deprived_count' => $statusCounts['deprived'],
            'withdrawn_count' => $statusCounts['withdrawn'],
            'average_final_mark' => $finalMarks->isNotEmpty() ? round($finalMarks->avg(), 2) : null,
            'highest_final_mark' => $finalMarks->isNotEmpty() ? round($finalMarks->max(), 2) : null,
            'lowest_final_mark' => $finalMarks->isNotEmpty() ? round($finalMarks->min(), 2) : null,
            'pass_rate' => $studentsWithResults > 0 ? round(($passedCount / $studentsWithResults) * 100, 2) : 0,
        ];
    }

    public function getCourseStatistics(int $courseId, ?int $academicYearId = null, ?int $semesterId = null, ?\App\Models\User $user = null): array
    {
        $course = Course::query()->findOrFail($courseId);

        $offeringQuery = CourseOffering::query();
        if ($user !== null) $offeringQuery = app(DataScopeService::class)->scopeOfferings($offeringQuery, $user);
        $offeringIds = $offeringQuery
            ->where('course_id', $courseId)
            ->when($academicYearId, fn (Builder $query) => $query->where('academic_year_id', $academicYearId))
            ->when($semesterId, fn (Builder $query) => $query->where('semester_id', $semesterId))
            ->pluck('course_offering_id');

        $registrations = $this->loadActiveRegistrationsForOfferings($offeringIds);

        return array_merge([
            'course_id' => $course->course_id,
            'course_code' => $course->course_code,
            'course_name' => $course->course_name,
            'program_requirement_classifications' => CourseRequirementClassification::programClassificationsForCourse(
                tap($course, function (Course $model) use ($user): void {
                    if ($user !== null) {
                        CourseRequirementClassification::hydrateCoursesForUser([$model], $user);

                        return;
                    }

                    CourseRequirementClassification::hydrateCourses([$model]);
                })
            ),
            'academic_year_id' => $academicYearId,
            'semester_id' => $semesterId,
            'offerings_count' => $offeringIds->count(),
        ], $this->buildStatistics($registrations));
    }

    public function getDepartmentStatistics(int $departmentId, ?int $academicYearId = null, ?int $semesterId = null, ?\App\Models\User $user = null): array
    {
        $department = Department::query()->findOrFail($departmentId);

        $offeringQuery = CourseOffering::query();
        if ($user !== null) $offeringQuery = app(DataScopeService::class)->scopeOfferings($offeringQuery, $user);
        $offeringIds = $offeringQuery
            ->where('department_id', $departmentId)
            ->when($academicYearId, fn (Builder $query) => $query->where('academic_year_id', $academicYearId))
            ->when($semesterId, fn (Builder $query) => $query->where('semester_id', $semesterId))
            ->pluck('course_offering_id');

        $registrations = $this->loadActiveRegistrationsForOfferings($offeringIds, withCourse: true);

        $byCourse = $registrations
            ->groupBy(fn (StudentCourseRegistration $registration) => $registration->courseOffering?->course_id)
            ->map(function (Collection $courseRegistrations) {
                $course = $courseRegistrations->first()?->courseOffering?->course;

                return array_merge([
                    'course_id' => $course?->course_id,
                    'course_code' => $course?->course_code,
                    'course_name' => $course?->course_name,
                ], $this->buildStatistics($courseRegistrations));
            })
            ->values()
            ->all();

        return array_merge([
            'department_id' => $department->department_id,
            'department_name' => $department->department_name,
            'academic_year_id' => $academicYearId,
            'semester_id' => $semesterId,
            'offerings_count' => $offeringIds->count(),
            'courses_count' => count($byCourse),
        ], $this->buildStatistics($registrations), [
            'by_course' => $byCourse,
        ]);
    }

    /**
     * Load active/historical registrations (with results) for a set of
     * course_offering_ids. Reuses the SAME currentOrHistoricalWithResult()
     * scope that getResultsSummary() and getGradeSheet() already use —
     * does not touch or duplicate their logic differently.
     */
    private function loadActiveRegistrationsForOfferings(Collection $offeringIds, bool $withCourse = false): Collection
    {
        if ($offeringIds->isEmpty()) {
            return collect();
        }

        $with = ['studentCourseResult.resultStatus', 'registrationStatus'];
        if ($withCourse) {
            $with[] = 'courseOffering.course';
        }

        return StudentCourseRegistration::query()
            ->whereIn('course_offering_id', $offeringIds)
            ->with($with)
            ->currentOrHistoricalWithResult()
            ->get();
    }

    /**
     * Compute pass/fail/mark statistics for an arbitrary collection of
     * student_course_registrations. Independent copy of the same logic used
     * inside getResultsSummary() — does not call or modify getResultsSummary().
     */
    private function buildStatistics(Collection $registrations): array
    {
        $withResults = $registrations->filter(fn (StudentCourseRegistration $registration) => $registration->studentCourseResult !== null);
        $finalMarks = $withResults
            ->map(fn (StudentCourseRegistration $registration) => (float) $registration->studentCourseResult->final_mark)
            ->values();

        $statusCounts = [
            'passed' => 0,
            'failed' => 0,
            'incomplete' => 0,
            'deprived' => 0,
            'withdrawn' => 0,
        ];

        foreach ($withResults as $registration) {
            $statusCode = $this->resolveEffectiveResultStatusCode($registration);
            if (array_key_exists($statusCode, $statusCounts)) {
                $statusCounts[$statusCode]++;
            }
        }

        $passedCount = $statusCounts['passed'];
        $studentsWithResults = $withResults->count();

        return [
            'total_registered_students' => $registrations->count(),
            'total_students_with_results' => $studentsWithResults,
            'passed_count' => $passedCount,
            'failed_count' => $statusCounts['failed'],
            'incomplete_count' => $statusCounts['incomplete'],
            'deprived_count' => $statusCounts['deprived'],
            'withdrawn_count' => $statusCounts['withdrawn'],
            'average_final_mark' => $finalMarks->isNotEmpty() ? round($finalMarks->avg(), 2) : null,
            'highest_final_mark' => $finalMarks->isNotEmpty() ? round($finalMarks->max(), 2) : null,
            'lowest_final_mark' => $finalMarks->isNotEmpty() ? round($finalMarks->min(), 2) : null,
            'pass_rate' => $studentsWithResults > 0 ? round(($passedCount / $studentsWithResults) * 100, 2) : 0,
        ];
    }

    public function getRegistrationGrades(int $registrationId): array
    {
        $registration = $this->loadRegistration($registrationId);

        return $this->formatRegistrationGrades($registration);
    }

    public function createRegistrationGrades(int $registrationId, array $data, ?int $userId = null): array
    {
        return DB::transaction(function () use ($registrationId, $data, $userId): array {
            $registration = $this->lockRegistrationWorkflow($registrationId);
            $this->assertLegacyGradeWorkflowAllowed((int) $registration->course_offering_id);
            $this->assertRegistrationAllowsGrading($registration);
            $this->assertOfferingGradesEditable((int) $registration->course_offering_id);

            if ($registration->studentCourseResult !== null) {
                throw new GradeException('Grades already exist for this registration. Use update endpoint instead.');
            }

            $this->assertRequestedGradePartsEditable((int) $registration->course_offering_id, $data);
            $result = $this->persistGrades($registration, $data, $userId, isUpdate: false, changedParts: ['theoretical', 'practical']);

            return $this->formatRegistrationGrades($registration->fresh()->load($this->registrationRelations()));
        });
    }

    public function updateRegistrationGrades(int $registrationId, array $data, ?int $userId = null): array
    {
        return DB::transaction(function () use ($registrationId, $data, $userId): array {
            $registration = $this->lockRegistrationWorkflow($registrationId);
            $this->assertLegacyGradeWorkflowAllowed((int) $registration->course_offering_id);
            $this->assertRegistrationAllowsGrading($registration);
            $this->assertOfferingGradesEditable((int) $registration->course_offering_id);

            if ($registration->studentCourseResult === null) {
                throw new GradeException('No grades found for this registration. Use create endpoint first.');
            }

            $changedParts = collect(['theoretical', 'practical'])
                ->filter(fn (string $part): bool => array_key_exists($part.'_mark', $data))->values()->all();
            if ($changedParts === []) {
                throw new GradeException('At least one grade part must be provided.', status: 422, errorCode: 'invalid_grade_part');
            }
            $this->assertRequestedGradePartsEditable((int) $registration->course_offering_id, $data);

            $oldTheoretical = (float) $registration->studentCourseResult->theoretical_total;
            $oldPractical = (float) $registration->studentCourseResult->practical_total;
            $data['theoretical_mark'] ??= $oldTheoretical;
            $data['practical_mark'] ??= $oldPractical;

            $this->persistGrades($registration, $data, $userId, isUpdate: true, changedParts: $changedParts);

            $this->createAuditLogs(
                $registration, $oldTheoretical, $oldPractical,
                (float) $data['theoretical_mark'], (float) $data['practical_mark'],
                $userId, $data['notes'] ?? 'Grade update', $changedParts
            );

            return $this->formatRegistrationGrades($registration->fresh()->load($this->registrationRelations()));
        });
    }

    public function calculateRegistrationResult(int $registrationId, ?int $userId = null): array
    {
        return DB::transaction(function () use ($registrationId, $userId): array {
            $registration = $this->lockRegistrationWorkflow($registrationId);
            $this->assertLegacyGradeWorkflowAllowed((int) $registration->course_offering_id);
            $this->assertRegistrationAllowsGrading($registration);
            $this->assertOfferingGradesEditable((int) $registration->course_offering_id);
            $result = $registration->studentCourseResult;

            if ($result === null) {
                throw new GradeException('No grades found for this registration.');
            }

            $existingStatusCode = $result->resultStatus?->status_code;
            if ($existingStatusCode === 'deprived' || $result->is_deprived) {
                throw new GradeException('Deprived results cannot be recalculated automatically.');
            }

            $theoretical = $result->theoretical_total !== null ? (float) $result->theoretical_total : null;
            $practical = $result->practical_total !== null ? (float) $result->practical_total : null;
            $calculation = $this->buildCalculation($theoretical, $practical, $existingStatusCode, (bool) $result->is_deprived);

            $result->update([
                'final_mark' => $calculation['final_mark'],
                'result_status_id' => $this->resultStatusId($calculation['result_status_code']),
                'calculated_at' => now(),
                'calculated_by_user_id' => $userId,
            ]);

            $registration->update([
                'result_status_id' => $this->resultStatusId($calculation['result_status_code']),
            ]);

            return [
                'registration_id' => $registration->student_course_registration_id,
                'theoretical_mark' => $theoretical,
                'practical_mark' => $practical,
                'final_mark' => $calculation['final_mark'],
                'letter_grade' => $calculation['letter_grade'],
                'grade_points' => $calculation['grade_points'],
                'result_status' => $this->compactResultStatus($calculation['result_status_code']),
                'calculation_details' => $calculation['calculation_details'],
            ];
        });
    }

    public function getTranscript(Student $student): array
    {
        $student->load(['currentAcademicLevel', 'academicProgram.department.college']);

        $registrations = $this->officialAcademicAttempts($student)
            ->get()
            ->filter(fn (StudentCourseRegistration $registration): bool => $this->isOfficiallyVisibleAttempt($registration))
            ->values();
        $registrations->each(fn (StudentCourseRegistration $registration) => $registration->setRelation('student', $student));
        CourseRequirementClassification::attachStudentProgramCourses($registrations);

        $terms = $registrations
            ->groupBy(fn (StudentCourseRegistration $registration) => ($registration->courseOffering?->academic_year_id ?? 'none').'-'.($registration->courseOffering?->semester_id ?? 'none'))
            ->map(function (Collection $termRegistrations) {
                $first = $termRegistrations->first();
                $year = $first?->courseOffering?->academicYear;
                $semester = $first?->courseOffering?->semester;
                $courses = $termRegistrations
                    ->sortBy(fn (StudentCourseRegistration $registration) => $registration->courseOffering?->course?->course_code ?? '')
                    ->map(fn (StudentCourseRegistration $registration) => $this->formatTranscriptCourse($registration))
                    ->values();

                $gpaEvaluation = $this->summarizeGpaCollection($termRegistrations);

                return [
                    'academic_year' => $this->compactAcademicYear($year),
                    'semester' => $this->compactSemester($semester),
                    'term_gpa' => $gpaEvaluation['gpa'],
                    'included_credit_hours' => $gpaEvaluation['included_credit_hours'],
                    'courses' => $courses->all(),
                    '_sort_start' => $year?->start_date?->format('Y-m-d') ?? '9999-99-99',
                    '_sort_order' => (int) ($semester?->semester_order ?? 9999),
                ];
            })
            ->sortBy(fn (array $term): string => $term['_sort_start'].'-'.str_pad((string) $term['_sort_order'], 4, '0', STR_PAD_LEFT))
            ->map(function (array $term): array {
                unset($term['_sort_start'], $term['_sort_order']);

                return $term;
            })
            ->values()
            ->all();

        $identity = $this->officialStudentIdentity($student);
        $cgpaEvaluation = $this->summarizeGpaCollection($this->selectBestAttempts($registrations));
        $summary = $this->officialTranscriptSummary($registrations, $cgpaEvaluation['gpa']);

        return array_merge($identity, [
            'student' => $identity,
            'summary' => $summary,
            'terms' => $terms,
        ]);
    }

    public function calculateGpa(Student $student, int $academicYearId, int $semesterId): array
    {
        $student->loadMissing(['academicProgram']);

        $registrations = $this->gpaEligibleRegistrations($student)
            ->whereHas('courseOffering', fn (Builder $query) => $query
                ->where('academic_year_id', $academicYearId)
                ->where('semester_id', $semesterId))
            ->get();

        return $this->buildGpaResponse(
            $student,
            $registrations,
            academicYearId: $academicYearId,
            semesterId: $semesterId
        );
    }

    public function calculateCgpa(Student $student): array
    {
        $student->loadMissing(['academicProgram']);

        $registrations = $this->selectBestAttempts(
            $this->gpaEligibleRegistrations($student)->get()
        );

        return $this->buildGpaResponse(
            $student,
            $registrations,
            repeatedCoursesHandling: 'highest_attempt_only'
        );
    }

    public function getGpaOverview(Student $student): array
    {
        $student->load(['currentAcademicLevel', 'academicProgram.department.college']);

        $registrations = $this->officialAcademicAttempts($student)
            ->get()
            ->filter(fn (StudentCourseRegistration $registration): bool => $this->isOfficiallyVisibleAttempt($registration))
            ->values();
        $registrations->each(fn (StudentCourseRegistration $registration) => $registration->setRelation('student', $student));
        CourseRequirementClassification::attachStudentProgramCourses($registrations);

        $termGroups = $registrations
            ->groupBy(function (StudentCourseRegistration $registration): string {
                $offering = $registration->courseOffering;

                return ($offering?->academic_year_id ?? 'none').'-'.($offering?->semester_id ?? 'none');
            })
            ->map(function (Collection $termRegistrations): array {
                $first = $termRegistrations->first();
                $year = $first?->courseOffering?->academicYear;
                $semester = $first?->courseOffering?->semester;
                $summary = $this->summarizeGpaCollection($termRegistrations);

                return [
                    'academic_year_id' => $year?->academic_year_id,
                    'year_name' => $year?->year_name,
                    'semester_id' => $semester?->semester_id,
                    'semester_code' => $semester?->semester_code,
                    'semester_name' => $semester?->semester_name,
                    'semester_order' => $semester?->semester_order,
                    'term_gpa' => $summary['gpa'],
                    'included_credit_hours' => $summary['included_credit_hours'],
                    'included_courses_count' => $summary['included_courses_count'],
                    'courses' => $this->gpaEligibleCourseRows($termRegistrations),
                    'registrations' => $termRegistrations,
                    '_sort' => $this->officialTermChronologyKey($year, $semester),
                ];
            })
            ->sortBy('_sort')
            ->values();

        $attemptsThroughTerm = collect();
        $timeline = [];
        $years = [];

        foreach ($termGroups as $term) {
            $attemptsThroughTerm = $attemptsThroughTerm->concat($term['registrations']);
            $cumulative = $this->summarizeGpaCollection($this->selectBestAttempts($attemptsThroughTerm));
            $yearKey = $term['academic_year_id'] ?? 'none';

            if (! isset($years[$yearKey])) {
                $years[$yearKey] = [
                    'academic_year_id' => $term['academic_year_id'],
                    'year_name' => $term['year_name'],
                    'semesters' => [],
                    '_registrations' => collect(),
                    '_sort' => $term['_sort'],
                ];
            }

            $years[$yearKey]['_registrations'] = $years[$yearKey]['_registrations']->concat($term['registrations']);
            $years[$yearKey]['semesters'][] = [
                'semester_id' => $term['semester_id'],
                'semester_code' => $term['semester_code'],
                'semester_name' => $term['semester_name'],
                'semester_order' => $term['semester_order'],
                'term_gpa' => $term['term_gpa'],
                'cumulative_gpa_after_term' => $cumulative['gpa'],
                'included_credit_hours' => $term['included_credit_hours'],
                'included_courses_count' => $term['included_courses_count'],
                'courses' => $term['courses'],
            ];

            $timeline[] = [
                'academic_year_id' => $term['academic_year_id'],
                'year_name' => $term['year_name'],
                'semester_id' => $term['semester_id'],
                'semester_code' => $term['semester_code'],
                'semester_name' => $term['semester_name'],
                'semester_order' => $term['semester_order'],
                'label' => trim(($term['year_name'] ?? '').' · '.($term['semester_name'] ?? ''), ' ·'),
                'term_gpa' => $term['term_gpa'],
                'cumulative_gpa' => $cumulative['gpa'],
                'included_credit_hours' => $term['included_credit_hours'],
                'included_courses_count' => $term['included_courses_count'],
            ];
        }

        $yearPayload = collect($years)
            ->sortBy('_sort')
            ->map(function (array $year): array {
                $summary = $this->summarizeGpaCollection($year['_registrations']);

                return [
                    'academic_year_id' => $year['academic_year_id'],
                    'year_name' => $year['year_name'],
                    'year_gpa' => $summary['gpa'],
                    'included_credit_hours' => $summary['included_credit_hours'],
                    'semesters' => $year['semesters'],
                ];
            })
            ->values()
            ->all();

        $cgpaEvaluation = $this->summarizeGpaCollection($this->selectBestAttempts($registrations));
        $termPoints = collect($timeline)->filter(fn (array $point): bool => $point['term_gpa'] !== null);
        $highest = $termPoints->sortByDesc('term_gpa')->first();
        $lowest = $termPoints->sortBy('term_gpa')->first();

        return [
            'student' => $this->officialStudentIdentity($student),
            'scale' => [
                'maximum' => 4.0,
            ],
            'summary' => [
                'cgpa' => $cgpaEvaluation['gpa'],
                'total_included_credit_hours' => $cgpaEvaluation['included_credit_hours'],
                'approved_courses_count' => $registrations->count(),
                'completed_terms_count' => $termPoints->count(),
                'highest_term_gpa' => is_array($highest) ? $highest['term_gpa'] : null,
                'lowest_term_gpa' => is_array($lowest) ? $lowest['term_gpa'] : null,
                'highest_term' => $this->compactGpaTermHighlight($highest),
                'lowest_term' => $this->compactGpaTermHighlight($lowest),
                'repeated_courses_handling' => 'highest_attempt_only',
            ],
            'years' => $yearPayload,
            'timeline' => $timeline,
        ];
    }

    public function assertRequiredPartsPolicyCompatible(bool $requiresTheoretical, bool $requiresPractical, float $theoreticalMax, float $practicalMax): GradingPolicy
    {
        $policy = $this->defaultGradingPolicy();
        $requiredMaximum = ($requiresTheoretical ? $theoreticalMax : 0) + ($requiresPractical ? $practicalMax : 0);
        $policyMaximum = (float) $policy->theoretical_max_mark + (float) $policy->practical_max_mark;
        $partMaximumsMatch = (! $requiresTheoretical || abs($theoreticalMax - (float) $policy->theoretical_max_mark) <= 0.001)
            && (! $requiresPractical || abs($practicalMax - (float) $policy->practical_max_mark) <= 0.001);
        if (! $partMaximumsMatch
            || abs($requiredMaximum - $policyMaximum) > 0.001
            || (float) $policy->minimum_final_mark > $requiredMaximum) {
            throw new GradeException('The grading policy is incompatible with the required grade parts.', status: 409, errorCode: 'grading_policy_incompatible');
        }
        return $policy;
    }

    public function buildCalculationForRequiredParts(?float $theoretical, ?float $practical, bool $requiresTheoretical, bool $requiresPractical, float $theoreticalMax, float $practicalMax, ?string $existingStatusCode = null, bool $isDeprived = false): array
    {
        if (($requiresTheoretical && $theoretical === null) || ($requiresPractical && $practical === null)) {
            throw new GradeException('All required grade parts must be present before final calculation.', status: 409, errorCode: 'grade_part_incomplete');
        }

        $policy = $this->assertRequiredPartsPolicyCompatible($requiresTheoretical, $requiresPractical, $theoreticalMax, $practicalMax);
        $finalMark = round(($requiresTheoretical ? $theoretical : 0) + ($requiresPractical ? $practical : 0), 2);
        $failed = ($requiresTheoretical && $theoretical < (float) $policy->minimum_theoretical_mark)
            || ($requiresPractical && $practical < (float) $policy->minimum_practical_mark)
            || $finalMark < (float) $policy->minimum_final_mark;
        $status = ($existingStatusCode === 'deprived' || $isDeprived) ? 'deprived' : ($failed ? 'failed' : 'passed');
        $letterGrade = $status === 'deprived' ? 'Z' : ($failed ? 'F' : $this->letterGradeFromFinalMark($finalMark));

        return ['theoretical_mark' => $theoretical, 'practical_mark' => $practical, 'final_mark' => $finalMark,
            'result_status_code' => $status, 'letter_grade' => $letterGrade,
            'grade_points' => $this->resolveGradePoints($letterGrade, $status),
            'calculation_details' => ['requires_theoretical' => $requiresTheoretical, 'requires_practical' => $requiresPractical]];
    }

    public function buildCalculation(?float $theoretical, ?float $practical, ?string $existingStatusCode = null, bool $isDeprived = false): array
    {
        $policy = $this->defaultGradingPolicy();
        $finalMark = ($theoretical !== null && $practical !== null)
            ? round($theoretical + $practical, 2)
            : null;

        $resultStatusCode = $this->resolveResultStatusCode(
            $theoretical,
            $practical,
            $finalMark,
            $existingStatusCode,
            $isDeprived,
            $policy
        );

        $letterGrade = $this->resolveLetterGrade($finalMark, $resultStatusCode, $theoretical, $practical, $policy);
        $gradePoints = $this->resolveGradePoints($letterGrade, $resultStatusCode);

        return [
            'theoretical_mark' => $theoretical,
            'practical_mark' => $practical,
            'final_mark' => $finalMark,
            'result_status_code' => $resultStatusCode,
            'letter_grade' => $letterGrade,
            'grade_points' => $gradePoints,
            'calculation_details' => [
                'minimum_theoretical_mark' => (float) $policy->minimum_theoretical_mark,
                'minimum_practical_mark' => (float) $policy->minimum_practical_mark,
                'minimum_final_mark' => (float) $policy->minimum_final_mark,
                'theoretical_passed' => $theoretical !== null ? $theoretical >= (float) $policy->minimum_theoretical_mark : false,
                'practical_passed' => $practical !== null ? $practical >= (float) $policy->minimum_practical_mark : false,
                'final_passed' => $finalMark !== null ? $finalMark >= (float) $policy->minimum_final_mark : false,
            ],
        ];
    }

    private function persistGrades(StudentCourseRegistration $registration, array $data, ?int $userId, bool $isUpdate, array $changedParts): StudentCourseResult
    {
        $theoretical = round((float) $data['theoretical_mark'], 2);
        $practical = round((float) $data['practical_mark'], 2);
        $calculation = $this->buildCalculation($theoretical, $practical);

        $resultStatusId = $this->resultStatusId($calculation['result_status_code']);

        $resultValues = [
            'final_mark' => $calculation['final_mark'], 'result_status_id' => $resultStatusId,
            'is_deprived' => $calculation['result_status_code'] === 'deprived',
            'calculated_at' => now(), 'calculated_by_user_id' => $userId,
        ];
        if (! $isUpdate || in_array('theoretical', $changedParts, true)) $resultValues['theoretical_total'] = $theoretical;
        if (! $isUpdate || in_array('practical', $changedParts, true)) $resultValues['practical_total'] = $practical;
        if (! $isUpdate) $resultValues['coursework_total'] = 0;
        $result = StudentCourseResult::query()->updateOrCreate(
            ['student_course_registration_id' => $registration->student_course_registration_id], $resultValues
        );

        $registration->update([
            'result_status_id' => $resultStatusId,
            'notes' => $data['notes'] ?? $registration->notes,
        ]);

        $this->syncGradeComponents($registration, $theoretical, $practical, $userId, $isUpdate, $changedParts);

        return $result;
    }

    private function syncGradeComponents(
        StudentCourseRegistration $registration,
        float $theoretical,
        float $practical,
        ?int $userId,
        bool $isUpdate,
        array $changedParts
    ): void {
        $components = GradeComponent::query()
            ->where('course_offering_id', $registration->course_offering_id)
            ->get();

        $theoreticalComponent = $components->where('component_type', 'theoretical')->sortByDesc('max_mark')->first();
        $practicalComponent = $components->where('component_type', 'practical')->sortByDesc('max_mark')->first();

        if ($theoreticalComponent && in_array('theoretical', $changedParts, true)) {
            $this->upsertStudentGradeComponent($registration, $theoreticalComponent, $theoretical, $userId, $isUpdate);
        }

        if ($practicalComponent && in_array('practical', $changedParts, true)) {
            $this->upsertStudentGradeComponent($registration, $practicalComponent, $practical, $userId, $isUpdate);
        }
    }

    private function upsertStudentGradeComponent(
        StudentCourseRegistration $registration,
        GradeComponent $component,
        float $mark,
        ?int $userId,
        bool $isUpdate
    ): void {
        StudentGradeComponent::query()->updateOrCreate(
            [
                'student_course_registration_id' => $registration->student_course_registration_id,
                'grade_component_id' => $component->grade_component_id,
            ],
            [
                'mark' => $mark,
                'grade_status' => 'draft',
                'entered_by_user_id' => $userId,
                'entered_at' => now(),
            ]
        );
    }

    private function createAuditLogs(
        StudentCourseRegistration $registration,
        float $oldTheoretical,
        float $oldPractical,
        float $newTheoretical,
        float $newPractical,
        ?int $userId,
        string $reason,
        array $changedParts
    ): void {
        if ($userId === null) {
            return;
        }

        $components = $registration->studentGradeComponents()->with('gradeComponent')->get();

        foreach ($components as $component) {
            $type = $component->gradeComponent?->component_type;
            if (! in_array($type, $changedParts, true)) continue;
            $oldMark = $type === 'theoretical' ? $oldTheoretical : ($type === 'practical' ? $oldPractical : null);
            $newMark = $type === 'theoretical' ? $newTheoretical : ($type === 'practical' ? $newPractical : null);

            if ($oldMark === null || $newMark === null || $oldMark === $newMark) {
                continue;
            }

            GradeAuditLog::query()->create([
                'student_grade_component_id' => $component->student_grade_component_id,
                'old_mark' => $oldMark,
                'new_mark' => $newMark,
                'changed_by_user_id' => $userId,
                'change_reason' => $reason,
                'changed_at' => now(),
            ]);
        }
    }

    private function formatRegistrationGrades(StudentCourseRegistration $registration): array
    {
        $result = $registration->studentCourseResult;
        $theoretical = $result?->theoretical_total !== null ? (float) $result->theoretical_total : null;
        $practical = $result?->practical_total !== null ? (float) $result->practical_total : null;
        $statusCode = $this->resolveEffectiveResultStatusCode($registration);
        $calculation = $this->buildCalculation(
            $theoretical,
            $practical,
            $statusCode,
            (bool) ($result?->is_deprived ?? false)
        );

        return [
            'registration' => [
                'student_course_registration_id' => $registration->student_course_registration_id,
                'registration_date' => $registration->registration_date,
                'registration_status' => $this->compactRegistrationStatus($registration->registrationStatus?->status_code, $registration->registrationStatus?->status_name),
                'grade_entry_allowed' => $registration->allowsGradeEntry(),
                'grade_entry_blocked_reason' => $registration->allowsGradeEntry()
                    ? null
                    : 'Historical or inactive registrations are read-only.',
            ],
            'student' => $registration->student ? [
                'student_id' => $registration->student->student_id,
                'student_number' => $registration->student->student_number,
                'full_name' => trim($registration->student->first_name.' '.$registration->student->last_name),
            ] : null,
            'course' => $registration->courseOffering?->course ? [
                'course_id' => $registration->courseOffering->course->course_id,
                'course_code' => $registration->courseOffering->course->course_code,
                'course_name' => $registration->courseOffering->course->course_name,
                'credit_hours' => $registration->courseOffering->course->credit_hours,
                'requirement_classification' => CourseRequirementClassification::forOffering($registration->courseOffering),
            ] : null,
            'theoretical_mark' => $theoretical,
            'practical_mark' => $practical,
            'final_mark' => $calculation['final_mark'],
            'letter_grade' => $calculation['letter_grade'],
            'grade_points' => $calculation['grade_points'],
            'result_status' => $this->compactResultStatus($calculation['result_status_code']),
            'notes' => $registration->notes,
        ];
    }

    private function formatGradeSheetRow(StudentCourseRegistration $registration, bool $workflowEditable): array
    {
        $grades = $this->formatRegistrationGrades($registration);
        $registrationAllowsGradeEntry = $registration->allowsGradeEntry();
        $gradeEntryAllowed = $registrationAllowsGradeEntry && $workflowEditable;

        return [
            'student_course_registration_id' => $registration->student_course_registration_id,
            'student_id' => $registration->student_id,
            'student_number' => $registration->student?->student_number,
            'full_name' => $registration->student ? trim($registration->student->first_name.' '.$registration->student->last_name) : null,
            'has_existing_grade' => $registration->studentCourseResult !== null,
            'theoretical_mark' => $grades['theoretical_mark'],
            'practical_mark' => $grades['practical_mark'],
            'final_mark' => $grades['final_mark'],
            'letter_grade' => $grades['letter_grade'],
            'grade_points' => $grades['grade_points'],
            'result_status' => $grades['result_status'],
            'is_deprived' => (bool) ($registration->studentCourseResult?->is_deprived
                || $registration->studentCourseResult?->resultStatus?->status_code === 'deprived'),
            'registration_status' => $grades['registration']['registration_status'],
            'grade_entry_allowed' => $gradeEntryAllowed,
            'grade_entry_blocked_reason' => match (true) {
                $gradeEntryAllowed => null,
                ! $registrationAllowsGradeEntry => 'Historical or inactive registrations are read-only.',
                default => 'Grades have been submitted for approval and are currently locked.',
            },
            'notes' => $grades['notes'],
        ];
    }

    private function formatTranscriptCourse(StudentCourseRegistration $registration): array
    {
        $offering = $registration->courseOffering;
        $course = $offering?->course;
        $result = $registration->studentCourseResult;
        $visibility = $this->officialComponentVisibility($offering);
        $statusCode = $this->resolveEffectiveResultStatusCode($registration) ?? 'incomplete';
        $isDeprived = (bool) ($result?->is_deprived || $statusCode === 'deprived');
        $theoretical = $result?->theoretical_total !== null ? (float) $result->theoretical_total : null;
        $practical = $result?->practical_total !== null ? (float) $result->practical_total : null;
        $finalMark = $result?->final_mark !== null ? (float) $result->final_mark : null;
        $letterGrade = $this->letterGradeFromOfficialResult(
            $theoretical,
            $practical,
            $finalMark,
            $statusCode,
            $visibility
        );
        $loadedStatus = $result?->resultStatus;

        return [
            'registration_id' => $registration->student_course_registration_id,
            'course_offering_id' => $registration->course_offering_id,
            'course_id' => $course?->course_id,
            'course_code' => $course?->course_code,
            'course_name' => $course?->course_name,
            'credit_hours' => $course?->credit_hours,
            'requirement_classification' => CourseRequirementClassification::forStudent(
                $registration->student?->academic_program_id === null ? null : (int) $registration->student->academic_program_id,
                $course?->course_id === null ? null : (int) $course->course_id,
                $registration->relationLoaded('studentProgramCourse') ? $registration->getRelation('studentProgramCourse') : null
            ),
            'academic_year' => $this->compactAcademicYear($offering?->academicYear),
            'semester' => $this->compactSemester($offering?->semester),
            'grades' => [
                'theoretical_mark' => $visibility['theoretical'] ? $theoretical : null,
                'practical_mark' => $visibility['practical'] ? $practical : null,
                'final_mark' => $finalMark,
                'letter_grade' => $letterGrade,
                'grade_points' => $this->resolveGradePoints($letterGrade, $statusCode),
            ],
            'theoretical_mark' => $visibility['theoretical'] ? $theoretical : null,
            'practical_mark' => $visibility['practical'] ? $practical : null,
            'final_mark' => $finalMark,
            'letter_grade' => $letterGrade,
            'grade_points' => $this->resolveGradePoints($letterGrade, $statusCode),
            'is_deprived' => $isDeprived,
            'result_status' => [
                'status_code' => $statusCode,
                'status_name' => $loadedStatus?->status_name ?? ucfirst($statusCode),
            ],
        ];
    }

    private function buildGpaResponse(
        Student $student,
        Collection $registrations,
        ?int $academicYearId = null,
        ?int $semesterId = null,
        ?string $repeatedCoursesHandling = null
    ): array {
        $included = [];
        $excluded = [];
        $totalWeightedPoints = 0.0;
        $totalCreditHours = 0;

        foreach ($registrations as $registration) {
            $evaluation = $this->evaluateGpaCourse($registration);

            if ($evaluation['included']) {
                $included[] = $evaluation['course'];
                $totalWeightedPoints += $evaluation['grade_points'] * $evaluation['credit_hours'];
                $totalCreditHours += $evaluation['credit_hours'];
            } else {
                $excluded[] = $evaluation['course'];
            }
        }

        $gpa = $totalCreditHours > 0 ? round($totalWeightedPoints / $totalCreditHours, 2) : null;

        $academicYear = null;
        $semester = null;

        if ($academicYearId !== null) {
            $academicYear = $this->compactAcademicYear(
                AcademicYear::query()->find($academicYearId)
                    ?? $registrations->first()?->courseOffering?->academicYear
            );
        }

        if ($semesterId !== null) {
            $semester = $this->compactSemester(
                Semester::query()->find($semesterId)
                    ?? $registrations->first()?->courseOffering?->semester
            );
        }

        $response = [
            'student' => [
                'student_id' => $student->student_id,
                'student_number' => $student->student_number,
                'full_name' => trim($student->first_name.' '.$student->last_name),
            ],
            'total_included_credit_hours' => $totalCreditHours,
            'total_grade_points' => round($totalWeightedPoints, 2),
            'gpa' => $gpa,
            'cgpa' => $gpa,
            'included_courses_count' => count($included),
            'excluded_courses_count' => count($excluded),
            'included_courses' => $included,
            'excluded_courses' => $excluded,
        ];

        if ($academicYearId !== null) {
            $response['academic_year'] = $academicYear;
            $response['academic_year_id'] = $academicYearId;
        }

        if ($semesterId !== null) {
            $response['semester'] = $semester;
            $response['semester_id'] = $semesterId;
            unset($response['cgpa']);
        } else {
            unset($response['gpa']);
            $response['repeated_courses_handling'] = $repeatedCoursesHandling;
        }

        return $response;
    }

    private function evaluateGpaCourse(StudentCourseRegistration $registration): array
    {
        $registrationStatus = $registration->registrationStatus?->status_code;
        $result = $registration->studentCourseResult;
        $course = $registration->courseOffering?->course;
        $creditHours = (int) ($course?->credit_hours ?? 0);

        $base = [
            'course_id' => $course?->course_id,
            'course_code' => $course?->course_code,
            'course_name' => $course?->course_name,
            'credit_hours' => $creditHours,
            'registration_id' => $registration->student_course_registration_id,
            'requirement_classification' => CourseRequirementClassification::forStudent(
                $registration->student?->academic_program_id === null ? null : (int) $registration->student->academic_program_id,
                $course?->course_id === null ? null : (int) $course->course_id,
                $registration->relationLoaded('studentProgramCourse') ? $registration->getRelation('studentProgramCourse') : null
            ),
        ];

        if (in_array($registrationStatus, StudentCourseRegistration::EXCLUDED_STATUSES, true)) {
            return [
                'included' => false,
                'course' => array_merge($base, ['exclusion_reason' => $registrationStatus]),
                'grade_points' => 0,
                'credit_hours' => $creditHours,
            ];
        }

        if ($result === null) {
            return [
                'included' => false,
                'course' => array_merge($base, ['exclusion_reason' => 'no_result']),
                'grade_points' => 0,
                'credit_hours' => $creditHours,
            ];
        }

        if ($result->theoretical_total === null || $result->practical_total === null) {
            return [
                'included' => false,
                'course' => array_merge($base, ['exclusion_reason' => 'missing_marks']),
                'grade_points' => 0,
                'credit_hours' => $creditHours,
            ];
        }

        $statusCode = $this->resolveEffectiveResultStatusCode($registration);

        if (in_array($statusCode, self::EXCLUDED_RESULT_STATUSES, true)) {
            return [
                'included' => false,
                'course' => array_merge($base, ['exclusion_reason' => $statusCode]),
                'grade_points' => 0,
                'credit_hours' => $creditHours,
            ];
        }

        $calculation = $this->buildCalculation(
            (float) $result->theoretical_total,
            (float) $result->practical_total,
            $statusCode,
            (bool) $result->is_deprived
        );

        return [
            'included' => true,
            'course' => array_merge($base, [
                'final_mark' => $calculation['final_mark'],
                'letter_grade' => $calculation['letter_grade'],
                'grade_points' => $calculation['grade_points'],
                'result_status' => $statusCode,
            ]),
            'grade_points' => $calculation['grade_points'],
            'credit_hours' => $creditHours,
        ];
    }

    private function selectBestAttempts(Collection $registrations): Collection
    {
        return $registrations
            ->groupBy(fn (StudentCourseRegistration $registration) => $registration->courseOffering?->course_id)
            ->map(function (Collection $attempts) {
                $evaluated = $attempts->map(function (StudentCourseRegistration $registration) {
                    $evaluation = $this->evaluateGpaCourse($registration);

                    return [
                        'registration' => $registration,
                        'included' => $evaluation['included'],
                        'grade_points' => $evaluation['grade_points'],
                        'final_mark' => $registration->studentCourseResult?->final_mark ?? 0,
                    ];
                });

                $included = $evaluated->where('included', true);

                if ($included->isEmpty()) {
                    return $attempts->sortByDesc(fn (StudentCourseRegistration $registration) => $registration->student_course_registration_id)->first();
                }

                return $included
                    ->sortByDesc(fn (array $item) => [$item['grade_points'], $item['final_mark']])
                    ->first()['registration'];
            })
            ->values();
    }

    private function gpaEligibleRegistrations(Student $student): Builder
    {
        return $this->officialAcademicAttempts($student);
    }

    public function officialAcademicAttempts(Student $student): Builder
    {
        return StudentCourseRegistration::query()
            ->where('student_id', $student->student_id)
            ->academicAttempts()
            ->whereHas('studentCourseResult')
            ->whereIn('course_offering_id', function ($subquery): void {
                $this->constrainAuthoritativeApprovedGradeApproval($subquery);
            })
            ->with([
                'courseOffering.course',
                'courseOffering.academicYear',
                'courseOffering.semester',
                'courseOffering.gradeComponents',
                'courseOffering.gradeApprovals.approvalStatus',
                'studentCourseResult.resultStatus',
                'registrationStatus',
            ]);
    }

    /**
     * Official academic attempts that are visible on the canonical transcript.
     *
     * @return Collection<int, StudentCourseRegistration>
     */
    public function loadOfficialVisibleAttempts(Student $student): Collection
    {
        $student->loadMissing(['academicProgram']);

        $registrations = $this->officialAcademicAttempts($student)
            ->get()
            ->filter(fn (StudentCourseRegistration $registration): bool => $this->isOfficiallyVisibleAttempt($registration))
            ->values();
        $registrations->each(fn (StudentCourseRegistration $registration) => $registration->setRelation('student', $student));

        return $registrations;
    }

    /**
     * Term GPA / hours from the same official-attempt and GPA path as the transcript.
     *
     * @return array{
     *     term_gpa: ?float,
     *     cumulative_gpa: ?float,
     *     total_registered_hours: int,
     *     attempted_hours: int,
     *     earned_hours: int,
     *     included_credit_hours: int,
     *     official_attempts_count: int
     * }
     */
    public function officialTermMetrics(Student $student, int $academicYearId, int $semesterId): array
    {
        $all = $this->loadOfficialVisibleAttempts($student);
        $term = $all->filter(function (StudentCourseRegistration $registration) use ($academicYearId, $semesterId): bool {
            $offering = $registration->courseOffering;

            return (int) ($offering?->academic_year_id ?? 0) === $academicYearId
                && (int) ($offering?->semester_id ?? 0) === $semesterId;
        })->values();

        $termSummary = $this->summarizeGpaCollection($term);
        $academicYear = AcademicYear::query()->find($academicYearId);
        $semester = Semester::query()->find($semesterId);
        $termKey = $this->officialTermChronologyKey($academicYear, $semester);
        $throughTerm = $all->filter(function (StudentCourseRegistration $registration) use ($termKey): bool {
            $offering = $registration->courseOffering;

            return $this->officialTermChronologyKey($offering?->academicYear, $offering?->semester) <= $termKey;
        });
        $cumulative = $this->summarizeGpaCollection($this->selectBestAttempts($throughTerm));

        $attemptedHours = 0;
        $earnedHours = 0;
        foreach ($term as $registration) {
            $hours = (int) ($registration->courseOffering?->course?->credit_hours ?? 0);
            $attemptedHours += $hours;
            if ($this->isOfficiallyPassedAttempt($registration)) {
                $earnedHours += $hours;
            }
        }

        return [
            'term_gpa' => $termSummary['gpa'],
            'cumulative_gpa' => $cumulative['gpa'],
            'total_registered_hours' => $attemptedHours,
            'attempted_hours' => $attemptedHours,
            'earned_hours' => $earnedHours,
            'included_credit_hours' => $termSummary['included_credit_hours'],
            'official_attempts_count' => $term->count(),
        ];
    }

    /**
     * Cumulative official metrics reused by progression and graduation.
     *
     * @return array<string, mixed>
     */
    public function officialCumulativeMetrics(Student $student): array
    {
        $all = $this->loadOfficialVisibleAttempts($student);
        $cgpaEvaluation = $this->summarizeGpaCollection($this->selectBestAttempts($all));
        $summary = $this->officialTranscriptSummary($all, $cgpaEvaluation['gpa']);

        $completed = [];
        $failed = [];
        $failedCourseIds = [];

        foreach ($all as $registration) {
            $course = $registration->courseOffering?->course;
            $courseId = $course?->course_id === null ? null : (int) $course->course_id;
            $statusCode = $this->resolveEffectiveResultStatusCode($registration);
            $row = [
                'student_course_registration_id' => $registration->student_course_registration_id,
                'course_id' => $courseId,
                'course_code' => $course?->course_code,
                'course_name' => $course?->course_name,
                'credit_hours' => (int) ($course?->credit_hours ?? 0),
                'result_status' => $statusCode,
                'final_mark' => $registration->studentCourseResult?->final_mark !== null
                    ? (float) $registration->studentCourseResult->final_mark
                    : null,
            ];

            if ($this->isOfficiallyPassedAttempt($registration) && $courseId !== null && ! isset($completed[$courseId])) {
                $completed[$courseId] = $row;
            }

            if ($statusCode === 'failed') {
                $failed[] = $row;
                if ($courseId !== null) {
                    $failedCourseIds[$courseId] = true;
                }
            }
        }

        return [
            'scale' => [
                'maximum' => 4.0,
            ],
            'cumulative_gpa' => $cgpaEvaluation['gpa'],
            'earned_hours' => (int) ($summary['total_passed_credit_hours'] ?? 0),
            'attempted_hours' => (int) ($summary['total_attempted_credit_hours'] ?? 0),
            'failed_courses_count' => count($failedCourseIds),
            'official_completed_courses' => array_values($completed),
            'failed_courses' => $failed,
            'repeated_courses_handling' => 'highest_attempt_only',
            'summary' => $summary,
        ];
    }

    /**
     * Current or historical academic work that is not yet an official visible attempt.
     *
     * Academic attempts are the existing HISTORICAL_ATTEMPT_STATUSES
     * (`registered` / `completed`). Dropped and withdrawn registrations are
     * excluded by that canonical scope and do not require a final result.
     *
     * @return list<array<string, mixed>>
     */
    public function unfinalizedAcademicWork(Student $student): array
    {
        return $this->collectUnfinalizedAcademicWork($student);
    }

    /**
     * Unfinalized academic attempts for one student/year/semester.
     *
     * Reuses the same official-result definition as unfinalizedAcademicWork().
     *
     * @return list<array<string, mixed>>
     */
    public function unfinalizedAcademicWorkForTerm(Student $student, int $academicYearId, int $semesterId): array
    {
        return $this->collectUnfinalizedAcademicWork($student, $academicYearId, $semesterId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectUnfinalizedAcademicWork(
        Student $student,
        ?int $academicYearId = null,
        ?int $semesterId = null
    ): array {
        $query = StudentCourseRegistration::query()
            ->where('student_id', $student->student_id)
            ->academicAttempts(requireResult: false)
            ->with([
                'courseOffering.course',
                'courseOffering.gradeApprovals.approvalStatus',
                'studentCourseResult.resultStatus',
                'registrationStatus',
            ])
            ->orderBy('student_course_registration_id');

        if ($academicYearId !== null && $semesterId !== null) {
            $query->whereHas('courseOffering', function ($offering) use ($academicYearId, $semesterId): void {
                $offering->where('academic_year_id', $academicYearId)
                    ->where('semester_id', $semesterId);
            });
        }

        $items = [];
        foreach ($query->get() as $registration) {
            if ($this->isOfficiallyVisibleAttempt($registration)) {
                continue;
            }

            $offering = $registration->courseOffering;
            $items[] = [
                'student_course_registration_id' => $registration->student_course_registration_id,
                'course_offering_id' => $registration->course_offering_id,
                'academic_year_id' => $offering?->academic_year_id,
                'semester_id' => $offering?->semester_id,
                'course_id' => $offering?->course_id ?? $offering?->course?->course_id,
                'course_code' => $offering?->course?->course_code,
                'registration_status' => $registration->registrationStatus?->status_code,
                'has_result' => $registration->studentCourseResult !== null,
                'reason' => $registration->studentCourseResult === null
                    ? 'no_official_result'
                    : 'grade_approval_not_approved',
            ];
        }

        return $items;
    }

    /**
     * Offering ids that must be locked before re-reading official results.
     *
     * @return list<int>
     */
    public function officialLockOfferingIds(Student $student): array
    {
        return StudentCourseRegistration::query()
            ->where('student_id', $student->student_id)
            ->academicAttempts(requireResult: false)
            ->orderBy('course_offering_id')
            ->pluck('course_offering_id')
            ->unique()
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function constrainAuthoritativeApprovedGradeApproval($subquery): void
    {
        $subquery->from('grade_approvals')
            ->join('approval_statuses', 'approval_statuses.approval_status_id', '=', 'grade_approvals.approval_status_id')
            ->select('grade_approvals.course_offering_id')
            ->where('approval_statuses.status_code', 'approved')
            ->whereRaw(
                'grade_approvals.grade_approval_id = (
                    SELECT MAX(latest_grade_approvals.grade_approval_id)
                    FROM grade_approvals AS latest_grade_approvals
                    WHERE latest_grade_approvals.course_offering_id = grade_approvals.course_offering_id
                )'
            );
    }

    public function isOfficiallyVisibleAttempt(StudentCourseRegistration $registration): bool
    {
        return $registration->studentCourseResult !== null
            && $this->isOfficiallyApprovedOffering($registration->courseOffering);
    }

    public function isOfficiallyPassedAttempt(StudentCourseRegistration $registration): bool
    {
        return $this->isOfficiallyVisibleAttempt($registration)
            && $this->resolveEffectiveResultStatusCode($registration) === 'passed';
    }

    public function officialAttemptResultStatus(StudentCourseRegistration $registration): ?string
    {
        if (! $this->isOfficiallyVisibleAttempt($registration)) {
            return null;
        }

        return $this->resolveEffectiveResultStatusCode($registration);
    }

    public function isOfficiallyApprovedOffering(?CourseOffering $offering): bool
    {
        if ($offering === null || $offering->course_offering_id === null) {
            return false;
        }

        if ($offering->relationLoaded('gradeApprovals')) {
            return $this->latestApprovalIsApproved($offering->gradeApprovals);
        }

        return CourseOffering::query()
            ->whereKey($offering->course_offering_id)
            ->whereIn('course_offering_id', function ($subquery): void {
                $this->constrainAuthoritativeApprovedGradeApproval($subquery);
            })
            ->exists();
    }

    public function scopeOfficialApprovedResults(Builder $query, ?int $studentId = null): Builder
    {
        return $query->whereHas('studentCourseRegistration', function (Builder $registration) use ($studentId): void {
            if ($studentId !== null) {
                $registration->where('student_id', $studentId);
            }

            $registration->whereIn('course_offering_id', function ($subquery): void {
                $this->constrainAuthoritativeApprovedGradeApproval($subquery);
            });
        });
    }

    private function officialComponentVisibility(?CourseOffering $offering): array
    {
        $required = $offering?->gradeComponents
            ? $offering->gradeComponents
                ->where('is_required', true)
                ->pluck('component_type')
                ->unique()
                ->values()
            : collect();

        if ($required->isNotEmpty()) {
            return [
                'theoretical' => $required->contains('theoretical'),
                'practical' => $required->contains('practical'),
            ];
        }

        $theoryHours = (float) ($offering?->course?->theoretical_hours ?? 0);
        $practicalHours = (float) ($offering?->course?->practical_hours ?? 0);

        if ($theoryHours > 0 && $practicalHours <= 0) {
            return ['theoretical' => true, 'practical' => false];
        }

        if ($practicalHours > 0 && $theoryHours <= 0) {
            return ['theoretical' => false, 'practical' => true];
        }

        return ['theoretical' => true, 'practical' => true];
    }

    private function letterGradeFromOfficialResult(
        ?float $theoretical,
        ?float $practical,
        ?float $finalMark,
        string $statusCode,
        array $visibility
    ): string {
        $policy = $this->defaultGradingPolicy();
        $theoreticalForPolicy = $visibility['theoretical']
            ? $theoretical
            : ($theoretical ?? (float) $policy->minimum_theoretical_mark);
        $practicalForPolicy = $visibility['practical']
            ? $practical
            : ($practical ?? (float) $policy->minimum_practical_mark);

        return $this->resolveLetterGrade(
            $finalMark,
            $statusCode,
            $theoreticalForPolicy,
            $practicalForPolicy,
            $policy
        );
    }

    private function latestApprovalIsApproved(Collection $approvals): bool
    {
        if ($approvals->isEmpty()) {
            return false;
        }

        $latest = $approvals
            ->sortByDesc(fn (GradeApproval $approval): int => (int) $approval->grade_approval_id)
            ->first();
        $latest?->loadMissing('approvalStatus');

        return $latest?->approvalStatus?->status_code === 'approved';
    }

    private function summarizeGpaCollection(Collection $registrations): array
    {
        $totalWeightedPoints = 0.0;
        $totalCreditHours = 0;
        $includedCoursesCount = 0;

        foreach ($registrations as $registration) {
            $evaluation = $this->evaluateGpaCourse($registration);
            if (! $evaluation['included']) {
                continue;
            }

            $totalWeightedPoints += $evaluation['grade_points'] * $evaluation['credit_hours'];
            $totalCreditHours += $evaluation['credit_hours'];
            $includedCoursesCount++;
        }

        return [
            'gpa' => $totalCreditHours > 0 ? round($totalWeightedPoints / $totalCreditHours, 2) : null,
            'included_credit_hours' => $totalCreditHours,
            'included_courses_count' => $includedCoursesCount,
        ];
    }

    private function officialTranscriptSummary(Collection $registrations, ?float $cgpa): array
    {
        $passed = 0;
        $failed = 0;
        $deprived = 0;
        $attemptedHours = 0;
        $passedHours = 0;
        $failedHours = 0;

        foreach ($registrations as $registration) {
            $hours = (int) ($registration->courseOffering?->course?->credit_hours ?? 0);
            $attemptedHours += $hours;
            $statusCode = $this->resolveEffectiveResultStatusCode($registration);
            $isDeprived = (bool) ($registration->studentCourseResult?->is_deprived || $statusCode === 'deprived');

            if ($isDeprived) {
                $deprived++;
                continue;
            }

            if ($statusCode === 'passed') {
                $passed++;
                $passedHours += $hours;
            } elseif ($statusCode === 'failed') {
                $failed++;
                $failedHours += $hours;
            }
        }

        return [
            'approved_courses_count' => $registrations->count(),
            'passed_courses_count' => $passed,
            'failed_courses_count' => $failed,
            'deprived_courses_count' => $deprived,
            'total_attempted_credit_hours' => $attemptedHours,
            'total_passed_credit_hours' => $passedHours,
            'total_failed_credit_hours' => $failedHours,
            'cgpa' => $cgpa,
        ];
    }

    private function officialStudentIdentity(Student $student): array
    {
        $program = $student->academicProgram;
        $department = $program?->department;

        return [
            'student_id' => $student->student_id,
            'student_number' => $student->student_number,
            'full_name' => trim($student->first_name.' '.$student->last_name),
            'program' => $program ? [
                'academic_program_id' => $program->academic_program_id,
                'program_code' => $program->program_code,
                'program_name' => $program->program_name,
            ] : null,
            'department' => $department ? [
                'department_id' => $department->department_id,
                'department_code' => $department->department_code,
                'department_name' => $department->department_name,
            ] : null,
            'college' => $department?->college ? [
                'college_id' => $department->college->college_id,
                'college_code' => $department->college->college_code,
                'college_name' => $department->college->college_name,
            ] : null,
            'academic_level' => $student->currentAcademicLevel ? [
                'academic_level_id' => $student->currentAcademicLevel->academic_level_id,
                'level_code' => $student->currentAcademicLevel->level_code,
                'level_name' => $student->currentAcademicLevel->level_name,
            ] : null,
        ];
    }

    private function loadRegistration(int $registrationId, bool $lock = false): StudentCourseRegistration
    {
        $query = StudentCourseRegistration::query()->with($this->registrationRelations());

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($registrationId);
    }

    private function lockRegistrationWorkflow(int $registrationId): StudentCourseRegistration
    {
        $offeringId = StudentCourseRegistration::query()
            ->whereKey($registrationId)
            ->value('course_offering_id');

        if ($offeringId !== null) {
            CourseOffering::query()->whereKey($offeringId)->lockForUpdate()->first();
        }

        return $this->loadRegistration($registrationId, lock: true);
    }

    private function assertOfferingGradesEditable(int $courseOfferingId): void
    {
        $approval = GradeApproval::query()
            ->where('course_offering_id', $courseOfferingId)
            ->with('approvalStatus')
            ->orderByDesc('grade_approval_id')
            ->first();

        if ($approval !== null && ! $approval->allowsGradeEditing()) {
            throw new GradeException(
                'Grades have been submitted and cannot be modified.',
                status: 409,
                errorCode: 'grades_locked'
            );
        }
    }

    private function registrationRelations(): array
    {
        return [
            'student',
            'registrationStatus',
            'courseOffering.course',
            'courseOffering.academicYear',
            'courseOffering.semester',
            'studentCourseResult.resultStatus',
        ];
    }

    public function assertLegacyGradeWorkflowAllowed(int $offeringId): void
    {
        $usesGradeParts = GradeComponent::query()->where('course_offering_id', $offeringId)
            ->where('is_required', true)->whereIn('component_type', GradePartApproval::PARTS)->exists();
        if ($usesGradeParts) {
            throw new GradeException(
                'This course offering must use the grade-parts workflow.',
                status: 409,
                errorCode: 'legacy_grade_workflow_disabled'
            );
        }
    }

    private function assertRequestedGradePartsEditable(int $offeringId, array $data): void
    {
        $requested = collect(GradePartApproval::PARTS)
            ->filter(fn (string $part): bool => array_key_exists($part.'_mark', $data))->values();
        if ($requested->isEmpty()) return;

        $locked = GradePartApproval::query()->where('course_offering_id', $offeringId)
            ->whereIn('component_type', $requested)->whereIn('status', ['submitted', 'approved'])
            ->lockForUpdate()->pluck('component_type')->values()->all();
        if ($locked !== []) {
            throw new GradeException('One or more requested grade parts are locked.', ['parts' => $locked], 409, 'grade_part_locked');
        }
    }

    private function assertRegistrationAllowsGrading(StudentCourseRegistration $registration): void
    {
        if (! $registration->allowsGradeEntry()) {
            throw new GradeException('Grades can only be entered for a current registered registration. Historical, dropped, and withdrawn registrations are read-only.');
        }
    }

    private function resolveEffectiveResultStatusCode(StudentCourseRegistration $registration): ?string
    {
        if ($registration->registrationStatus?->status_code === 'withdrawn') {
            return 'withdrawn';
        }

        return $registration->studentCourseResult?->resultStatus?->status_code
            ?? $registration->resultStatus?->status_code;
    }

    private function resolveResultStatusCode(
        ?float $theoretical,
        ?float $practical,
        ?float $finalMark,
        ?string $existingStatusCode,
        bool $isDeprived,
        GradingPolicy $policy
    ): string {
        if ($existingStatusCode === 'deprived' || $isDeprived) {
            return 'deprived';
        }

        if ($theoretical === null || $practical === null) {
            return 'incomplete';
        }

        if ($theoretical < (float) $policy->minimum_theoretical_mark
            || $practical < (float) $policy->minimum_practical_mark
            || ($finalMark !== null && $finalMark < (float) $policy->minimum_final_mark)) {
            return 'failed';
        }

        return 'passed';
    }

    private function resolveLetterGrade(
        ?float $finalMark,
        string $resultStatusCode,
        ?float $theoretical,
        ?float $practical,
        GradingPolicy $policy
    ): string {
        if ($resultStatusCode === 'deprived') {
            return 'Z';
        }

        if ($resultStatusCode === 'withdrawn') {
            return 'W';
        }

        if ($resultStatusCode === 'incomplete') {
            return 'I';
        }

        if ($resultStatusCode === 'failed'
            || $theoretical === null
            || $practical === null
            || $theoretical < (float) $policy->minimum_theoretical_mark
            || $practical < (float) $policy->minimum_practical_mark
            || $finalMark === null
            || $finalMark < (float) $policy->minimum_final_mark) {
            return 'F';
        }

        return $this->letterGradeFromFinalMark($finalMark);
    }

    private function letterGradeFromFinalMark(float $finalMark): string
    {
        return match (true) {
            $finalMark >= 98 => 'A+',
            $finalMark >= 95 => 'A',
            $finalMark >= 90 => 'A-',
            $finalMark >= 85 => 'B+',
            $finalMark >= 80 => 'B',
            $finalMark >= 75 => 'B-',
            $finalMark >= 70 => 'C+',
            $finalMark >= 65 => 'C',
            $finalMark >= 60 => 'C-',
            $finalMark >= 55 => 'D+',
            $finalMark >= 50 => 'D',
            default => 'F',
        };
    }

    private function resolveGradePoints(string $letterGrade, string $resultStatusCode): float
    {
        if (in_array($letterGrade, ['Z', 'W', 'I'], true) || in_array($resultStatusCode, self::EXCLUDED_RESULT_STATUSES, true)) {
            return 0.00;
        }

        return match ($letterGrade) {
            'A+' => 4.00,
            'A' => 3.75,
            'A-' => 3.50,
            'B+' => 3.25,
            'B' => 3.00,
            'B-' => 2.75,
            'C+' => 2.50,
            'C' => 2.25,
            'C-' => 2.00,
            'D+' => 1.75,
            'D' => 1.50,
            default => 0.00,
        };
    }

    private function resultStatusId(string $statusCode): int
    {
        $statusId = ResultStatus::query()->where('status_code', $statusCode)->value('result_status_id');

        if ($statusId === null && $statusCode === 'withdrawn') {
            throw new GradeException('Result status "withdrawn" was not found in result_statuses.');
        }

        if ($statusId === null) {
            throw new GradeException('Result status "'.$statusCode.'" was not found in result_statuses.');
        }

        return (int) $statusId;
    }

    private function defaultGradingPolicy(): GradingPolicy
    {
        if ($this->defaultPolicy === null) {
            $this->defaultPolicy = GradingPolicy::query()
                ->where('is_default', true)
                ->where('is_active', true)
                ->first()
                ?? GradingPolicy::query()->where('is_active', true)->first();

            if ($this->defaultPolicy === null) {
                throw new ModelNotFoundException('No active grading policy was found.');
            }
        }

        return $this->defaultPolicy;
    }

    private function compactAcademicYear($year): ?array
    {
        if ($year === null) {
            return null;
        }

        return [
            'academic_year_id' => $year->academic_year_id,
            'year_name' => $year->year_name,
        ];
    }

    private function compactSemester($semester): ?array
    {
        if ($semester === null) {
            return null;
        }

        return [
            'semester_id' => $semester->semester_id,
            'semester_code' => $semester->semester_code,
            'semester_name' => $semester->semester_name,
            'semester_order' => $semester->semester_order,
        ];
    }

    private function compactResultStatus(string $statusCode): array
    {
        $status = ResultStatus::query()->where('status_code', $statusCode)->first();

        return [
            'status_code' => $statusCode,
            'status_name' => $status?->status_name ?? ucfirst($statusCode),
        ];
    }

    private function compactRegistrationStatus(?string $statusCode, ?string $statusName): ?array
    {
        if ($statusCode === null) {
            return null;
        }

        return [
            'status_code' => $statusCode,
            'status_name' => $statusName,
        ];
    }

    private function gpaEligibleCourseRows(Collection $registrations): array
    {
        $rows = [];

        foreach ($registrations as $registration) {
            $evaluation = $this->evaluateGpaCourse($registration);
            if (! $evaluation['included']) {
                continue;
            }

            $course = $evaluation['course'];
            $rows[] = [
                'registration_id' => $course['registration_id'] ?? $registration->student_course_registration_id,
                'course_id' => $course['course_id'] ?? null,
                'course_code' => $course['course_code'] ?? null,
                'course_name' => $course['course_name'] ?? null,
                'credit_hours' => $course['credit_hours'] ?? 0,
                'requirement_classification' => $course['requirement_classification'] ?? null,
                'final_mark' => $course['final_mark'] ?? null,
                'letter_grade' => $course['letter_grade'] ?? null,
                'grade_points' => $course['grade_points'] ?? $evaluation['grade_points'],
            ];
        }

        return $rows;
    }

    private function officialTermChronologyKey($year, $semester): string
    {
        return ($year?->start_date?->format('Y-m-d') ?? '9999-99-99')
            .'-'.str_pad((string) ($semester?->semester_order ?? 9999), 4, '0', STR_PAD_LEFT);
    }

    private function compactGpaTermHighlight(?array $point): ?array
    {
        if ($point === null) {
            return null;
        }

        return [
            'academic_year_id' => $point['academic_year_id'] ?? null,
            'year_name' => $point['year_name'] ?? null,
            'semester_id' => $point['semester_id'] ?? null,
            'semester_name' => $point['semester_name'] ?? null,
            'term_gpa' => $point['term_gpa'] ?? null,
            'label' => $point['label'] ?? null,
        ];
    }
}
