<?php

namespace App\Services;

use App\Exceptions\GradeException;
use App\Models\AcademicYear;
use App\Models\CourseOffering;
use App\Models\GradeAuditLog;
use App\Models\GradeComponent;
use App\Models\GradingPolicy;
use App\Models\ResultStatus;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Models\StudentCourseResult;
use App\Models\StudentGradeComponent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GradeService
{
    private const ACTIVE_REGISTRATION_STATUS = 'registered';

    private const EXCLUDED_REGISTRATION_STATUSES = ['dropped', 'withdrawn'];

    private const EXCLUDED_RESULT_STATUSES = ['incomplete', 'deprived', 'withdrawn'];

    private ?GradingPolicy $defaultPolicy = null;

    public function getGradeSheet(int $courseOfferingId, bool $includeInactive = false): array
    {
        $offering = CourseOffering::query()
            ->with(['course', 'academicYear', 'semester'])
            ->findOrFail($courseOfferingId);

        $registrationsQuery = $offering->studentCourseRegistrations()
            ->with([
                'student',
                'registrationStatus',
                'studentCourseResult.resultStatus',
            ]);

        if (! $includeInactive) {
            $registrationsQuery->whereHas(
                'registrationStatus',
                fn (Builder $query) => $query->where('status_code', self::ACTIVE_REGISTRATION_STATUS)
            );
        }

        $registrations = $registrationsQuery
            ->orderBy('student_course_registration_id')
            ->get();

        return [
            'course_offering_id' => $offering->course_offering_id,
            'course_code' => $offering->course?->course_code,
            'course_name' => $offering->course?->course_name,
            'academic_year' => $this->compactAcademicYear($offering->academicYear),
            'semester' => $this->compactSemester($offering->semester),
            'students' => $registrations->map(fn (StudentCourseRegistration $registration) => $this->formatGradeSheetRow($registration))->values()->all(),
        ];
    }

    public function getResultsSummary(int $courseOfferingId): array
    {
        $offering = CourseOffering::query()->findOrFail($courseOfferingId);

        $registrations = $offering->studentCourseRegistrations()
            ->with(['studentCourseResult.resultStatus', 'registrationStatus'])
            ->whereHas(
                'registrationStatus',
                fn (Builder $query) => $query->where('status_code', self::ACTIVE_REGISTRATION_STATUS)
            )
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

    public function getRegistrationGrades(int $registrationId): array
    {
        $registration = $this->loadRegistration($registrationId);

        return $this->formatRegistrationGrades($registration);
    }

    public function createRegistrationGrades(int $registrationId, array $data, ?int $userId = null): array
    {
        return DB::transaction(function () use ($registrationId, $data, $userId): array {
            $registration = $this->loadRegistration($registrationId, lock: true);
            $this->assertRegistrationAllowsGrading($registration);

            if ($registration->studentCourseResult !== null) {
                throw new GradeException('Grades already exist for this registration. Use update endpoint instead.');
            }

            $result = $this->persistGrades($registration, $data, $userId, isUpdate: false);

            return $this->formatRegistrationGrades($registration->fresh()->load($this->registrationRelations()));
        });
    }

    public function updateRegistrationGrades(int $registrationId, array $data, ?int $userId = null): array
    {
        return DB::transaction(function () use ($registrationId, $data, $userId): array {
            $registration = $this->loadRegistration($registrationId, lock: true);

            if ($registration->studentCourseResult === null) {
                throw new GradeException('No grades found for this registration. Use create endpoint first.');
            }

            $oldTheoretical = (float) $registration->studentCourseResult->theoretical_total;
            $oldPractical = (float) $registration->studentCourseResult->practical_total;

            $this->persistGrades($registration, $data, $userId, isUpdate: true);

            $this->createAuditLogs(
                $registration,
                $oldTheoretical,
                $oldPractical,
                (float) $data['theoretical_mark'],
                (float) $data['practical_mark'],
                $userId,
                $data['notes'] ?? 'Grade update'
            );

            return $this->formatRegistrationGrades($registration->fresh()->load($this->registrationRelations()));
        });
    }

    public function calculateRegistrationResult(int $registrationId, ?int $userId = null): array
    {
        return DB::transaction(function () use ($registrationId, $userId): array {
            $registration = $this->loadRegistration($registrationId, lock: true);
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
        $student->load([
            'currentAcademicLevel',
            'academicProgram.department.college',
            'studentCourseRegistrations' => function ($query): void {
                $query->with([
                    'courseOffering.course',
                    'courseOffering.academicYear',
                    'courseOffering.semester',
                    'studentCourseResult.resultStatus',
                    'registrationStatus',
                ])->orderBy('student_course_registration_id');
            },
        ]);

        $grouped = $student->studentCourseRegistrations
            ->groupBy(fn (StudentCourseRegistration $registration) => $registration->courseOffering?->academic_year_id.'-'.$registration->courseOffering?->semester_id)
            ->map(function (Collection $registrations) {
                $first = $registrations->first();

                return [
                    'academic_year' => $this->compactAcademicYear($first?->courseOffering?->academicYear),
                    'semester' => $this->compactSemester($first?->courseOffering?->semester),
                    'courses' => $registrations->map(fn (StudentCourseRegistration $registration) => $this->formatTranscriptCourse($registration))->values()->all(),
                ];
            })
            ->values()
            ->all();

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
            'terms' => $grouped,
        ];
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

    private function persistGrades(StudentCourseRegistration $registration, array $data, ?int $userId, bool $isUpdate): StudentCourseResult
    {
        $theoretical = round((float) $data['theoretical_mark'], 2);
        $practical = round((float) $data['practical_mark'], 2);
        $calculation = $this->buildCalculation($theoretical, $practical);

        $resultStatusId = $this->resultStatusId($calculation['result_status_code']);

        $result = StudentCourseResult::query()->updateOrCreate(
            ['student_course_registration_id' => $registration->student_course_registration_id],
            [
                'theoretical_total' => $theoretical,
                'practical_total' => $practical,
                'coursework_total' => 0,
                'final_mark' => $calculation['final_mark'],
                'result_status_id' => $resultStatusId,
                'is_deprived' => $calculation['result_status_code'] === 'deprived',
                'calculated_at' => now(),
                'calculated_by_user_id' => $userId,
            ]
        );

        $registration->update([
            'result_status_id' => $resultStatusId,
            'notes' => $data['notes'] ?? $registration->notes,
        ]);

        $this->syncGradeComponents($registration, $theoretical, $practical, $userId, $isUpdate);

        return $result;
    }

    private function syncGradeComponents(
        StudentCourseRegistration $registration,
        float $theoretical,
        float $practical,
        ?int $userId,
        bool $isUpdate
    ): void {
        $components = GradeComponent::query()
            ->where('course_offering_id', $registration->course_offering_id)
            ->get();

        $theoreticalComponent = $components->where('component_type', 'theoretical')->sortByDesc('max_mark')->first();
        $practicalComponent = $components->where('component_type', 'practical')->sortByDesc('max_mark')->first();

        if ($theoreticalComponent) {
            $this->upsertStudentGradeComponent($registration, $theoreticalComponent, $theoretical, $userId, $isUpdate);
        }

        if ($practicalComponent) {
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
                'grade_status' => 'submitted',
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
        string $reason
    ): void {
        if ($userId === null) {
            return;
        }

        $components = $registration->studentGradeComponents()->with('gradeComponent')->get();

        foreach ($components as $component) {
            $type = $component->gradeComponent?->component_type;
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

    private function formatGradeSheetRow(StudentCourseRegistration $registration): array
    {
        $grades = $this->formatRegistrationGrades($registration);

        return [
            'student_course_registration_id' => $registration->student_course_registration_id,
            'student_id' => $registration->student_id,
            'student_number' => $registration->student?->student_number,
            'full_name' => $registration->student ? trim($registration->student->first_name.' '.$registration->student->last_name) : null,
            'theoretical_mark' => $grades['theoretical_mark'],
            'practical_mark' => $grades['practical_mark'],
            'final_mark' => $grades['final_mark'],
            'letter_grade' => $grades['letter_grade'],
            'grade_points' => $grades['grade_points'],
            'result_status' => $grades['result_status'],
            'registration_status' => $grades['registration']['registration_status'],
            'notes' => $grades['notes'],
        ];
    }

    private function formatTranscriptCourse(StudentCourseRegistration $registration): array
    {
        $grades = $this->formatRegistrationGrades($registration);

        return [
            'course_code' => $grades['course']['course_code'] ?? null,
            'course_name' => $grades['course']['course_name'] ?? null,
            'credit_hours' => $grades['course']['credit_hours'] ?? null,
            'theoretical_mark' => $grades['theoretical_mark'],
            'practical_mark' => $grades['practical_mark'],
            'final_mark' => $grades['final_mark'],
            'letter_grade' => $grades['letter_grade'],
            'grade_points' => $grades['grade_points'],
            'result_status' => $grades['result_status'],
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

        $gpa = $totalCreditHours > 0 ? round($totalWeightedPoints / $totalCreditHours, 2) : 0.00;

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
        ];

        if (in_array($registrationStatus, self::EXCLUDED_REGISTRATION_STATUSES, true)) {
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
        return StudentCourseRegistration::query()
            ->where('student_id', $student->student_id)
            ->with([
                'courseOffering.course',
                'courseOffering.academicYear',
                'courseOffering.semester',
                'studentCourseResult.resultStatus',
                'registrationStatus',
            ]);
    }

    private function loadRegistration(int $registrationId, bool $lock = false): StudentCourseRegistration
    {
        $query = StudentCourseRegistration::query()->with($this->registrationRelations());

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($registrationId);
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

    private function assertRegistrationAllowsGrading(StudentCourseRegistration $registration): void
    {
        $statusCode = $registration->registrationStatus?->status_code;

        if (in_array($statusCode, self::EXCLUDED_REGISTRATION_STATUSES, true)) {
            throw new GradeException('Grades cannot be entered for dropped or withdrawn registrations.');
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
}
