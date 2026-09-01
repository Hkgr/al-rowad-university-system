<?php

namespace App\Services;

use App\Exceptions\RegistrationException;
use App\Models\AcademicYear;
use App\Models\CourseOffering;
use App\Models\CoursePrerequisite;
use App\Models\ProgramCourse;
use App\Models\RegistrationStatus;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Models\StudentRegistrationRequest;
use App\Models\StudentRegistrationRequestItem;
use App\Support\AcademicCalendarPolicyResult;
use App\Support\CourseRequirementClassification;
use App\Support\CourseRegistrationDeadlineResult;
use App\Support\CourseRegistrationPhase;
use App\Support\RegistrationMaterializationContext;
use App\Support\SupplementaryExamTargetGuard;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RegistrationService
{
    public const DEFAULT_MAX_CREDIT_HOURS = 18;

    public const HIGH_CGPA_MAX_CREDIT_HOURS = 21;

    public const HIGH_CGPA_THRESHOLD = 3.0;

    public const RECOMMENDED_MINIMUM_CREDIT_HOURS = 12;

    /**
     * Canonical lock order: Student -> CourseOffering (ascending id) ->
     * StudentCourseRegistration (ascending id) -> current withdrawal request.
     * See RegistrationLifecycle.
     */

    private const COURSE_REGISTRATION_EVENT_TYPE = 'course_registration';

    public function __construct(
        private AcademicRequirementService $requirements,
        private AcademicCalendarPolicyService $academicCalendarPolicy,
        private GradeService $grades,
        private CourseOfferingScheduleService $schedules,
    ) {
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return StudentCourseRegistration::query()
            ->with(['student', 'courseOffering.course', 'courseOffering.academicYear', 'courseOffering.semester', 'registrationStatus', 'resultStatus'])
            ->latest('student_course_registration_id')
            ->paginate($perPage);
    }

    public function findOrFail(int $registrationId): StudentCourseRegistration
    {
        return StudentCourseRegistration::query()
            ->with(['student', 'courseOffering.course', 'courseOffering.academicYear', 'courseOffering.semester', 'registrationStatus', 'resultStatus'])
            ->findOrFail($registrationId);
    }

    public function registerStudent(array $data, ?int $authenticatedUserId = null): array
    {
        throw RegistrationException::liveWorkflowRequired();
    }

    public function registerStudentWithinTransaction(array $data, ?int $authenticatedUserId = null): array
    {
        try {
            return $this->performRegisterStudent(
                $data,
                $authenticatedUserId,
                RegistrationMaterializationContext::STUDENT_WINDOW,
            );
        } catch (QueryException $exception) {
            if ($this->isDuplicateRegistrationQueryException($exception)) {
                $this->throwDuplicateRegistrationException();
            }

            throw $exception;
        }
    }

    /** Trusted boundary bound to a current submitted request and its item. */
    public function materializeAdvisorApprovedRequestItemWithinTransaction(
        StudentRegistrationRequest $request,
        StudentRegistrationRequestItem $item,
        int $advisorUserId,
        ?CarbonInterface $at = null,
    ): array {
        if (DB::transactionLevel() < 1 || ! $request->exists || ! $item->exists) {
            throw RegistrationException::liveWorkflowRequired();
        }

        $lockedRequest = StudentRegistrationRequest::query()
            ->whereKey($request->getKey())
            ->lockForUpdate()
            ->first();
        $lockedItem = StudentRegistrationRequestItem::query()
            ->whereKey($item->getKey())
            ->lockForUpdate()
            ->first();

        if ($lockedRequest === null
            || $lockedItem === null
            || ! $lockedRequest->isSubmitted()
            || $lockedRequest->expired_at !== null
            || $lockedRequest->approved_at !== null
            || (int) $lockedItem->student_registration_request_id !== (int) $lockedRequest->getKey()
            || $lockedItem->student_course_registration_id !== null
        ) {
            throw RegistrationException::liveWorkflowRequired();
        }

        $offering = CourseOffering::query()
            ->whereKey($lockedItem->course_offering_id)
            ->lockForUpdate()
            ->first();
        if ($offering === null
            || (int) $offering->academic_year_id !== (int) $lockedRequest->academic_year_id
            || (int) $offering->semester_id !== (int) $lockedRequest->semester_id
        ) {
            throw RegistrationException::liveWorkflowRequired();
        }

        $evaluatedAt = $at === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::instance($at)->utc();
        $deadline = $this->courseRegistrationDeadlines(
            (int) $lockedRequest->academic_year_id,
            (int) $lockedRequest->semester_id,
            $evaluatedAt,
        );
        if ($deadline->phase === CourseRegistrationPhase::CONFIGURATION_ERROR) {
            $this->throwDeadlineConfigurationException($deadline);
        }
        if (! $deadline->isAdvisorDecisionOpen()
            || $lockedRequest->last_submitted_at === null
            || $deadline->startsAt === null
            || $deadline->studentRegistrationEndsAt === null
            || CarbonImmutable::instance($lockedRequest->last_submitted_at)->utc()->lt($deadline->startsAt)
            || CarbonImmutable::instance($lockedRequest->last_submitted_at)->utc()->gt($deadline->studentRegistrationEndsAt)
        ) {
            throw RegistrationException::courseRegistrationWindowClosed();
        }

        try {
            return $this->performRegisterStudent(
                [
                    'student_id' => (int) $lockedRequest->student_id,
                    'course_offering_id' => (int) $lockedItem->course_offering_id,
                    'advisor_user_id' => $advisorUserId,
                    'registration_date' => $evaluatedAt->toDateString(),
                ],
                $advisorUserId,
                RegistrationMaterializationContext::ADVISOR_APPROVAL,
            );
        } catch (QueryException $exception) {
            if ($this->isDuplicateRegistrationQueryException($exception)) {
                $this->throwDuplicateRegistrationException();
            }

            throw $exception;
        }
    }

    public function hoursSnapshot(Student $student, int $academicYearId, int $semesterId): array
    {
        return $this->getHoursSnapshot($student, $academicYearId, $semesterId);
    }

    public function currentOfferingIds(Student $student): array
    {
        return $this->currentRegisteredOfferingIds($student);
    }

    public function courseRegistrationWindow(int $academicYearId, int $semesterId): AcademicCalendarPolicyResult
    {
        return $this->academicCalendarPolicy->evaluate(
            self::COURSE_REGISTRATION_EVENT_TYPE,
            $academicYearId,
            $semesterId,
        );
    }

    public function courseRegistrationDeadlines(
        int $academicYearId,
        int $semesterId,
        ?CarbonInterface $at = null,
    ): CourseRegistrationDeadlineResult {
        return $this->academicCalendarPolicy->courseRegistrationDeadlines(
            $academicYearId,
            $semesterId,
            $at,
        );
    }

    public function assertCourseRegistrationWindowOpen(int $academicYearId, int $semesterId): void
    {
        $this->assertCourseRegistrationStudentWindowOpen($academicYearId, $semesterId);
    }

    public function assertCourseRegistrationStudentWindowOpen(
        int $academicYearId,
        int $semesterId,
        ?CarbonInterface $at = null,
    ): void {
        $result = $this->courseRegistrationDeadlines($academicYearId, $semesterId, $at);
        if ($result->phase === CourseRegistrationPhase::STUDENT_OPEN) {
            return;
        }
        if ($result->phase !== CourseRegistrationPhase::CONFIGURATION_ERROR) {
            throw RegistrationException::courseRegistrationWindowClosed();
        }
        $this->throwDeadlineConfigurationException($result);
    }

    private function throwDeadlineConfigurationException(CourseRegistrationDeadlineResult $result): never
    {
        if (in_array($result->reasonCode, ['unknown_academic_year', 'academic_year_not_operational'], true)) {
            throw RegistrationException::academicCalendarYearContextInvalid();
        }
        if (in_array($result->reasonCode, ['unknown_semester', 'semester_inactive'], true)) {
            throw RegistrationException::academicCalendarSemesterContextInvalid();
        }

        throw RegistrationException::academicCalendarConfigurationInvalid();
    }

    private function performRegisterStudent(
        array $data,
        ?int $authenticatedUserId,
        RegistrationMaterializationContext $context,
    ): array
    {
        $student = Student::query()
            ->whereKey($data['student_id'])
            ->lockForUpdate()
            ->first();
        if ($student === null) {
            throw new RegistrationException('The selected student does not exist.', [
                'student_id' => ['The selected student does not exist.'],
            ]);
        }

        $courseOffering = CourseOffering::query()
            ->with('course')
            ->whereKey($data['course_offering_id'])
            ->lockForUpdate()
            ->first();

        if ($courseOffering === null) {
            throw new RegistrationException('The selected course offering does not exist.', [
                'course_offering_id' => ['The selected course offering does not exist.'],
            ]);
        }

        if ($courseOffering->status !== 'open') {
            throw new RegistrationException('The selected course offering is not open for registration.', [
                'course_offering_id' => ['The selected course offering is not open for registration.'],
            ]);
        }

        StudentCourseRegistration::query()
            ->where('student_id', $student->student_id)
            ->where('course_offering_id', $courseOffering->course_offering_id)
            ->orderBy('student_course_registration_id')
            ->lockForUpdate()
            ->get();

        if ($this->registrationExists($student->student_id, $courseOffering->course_offering_id)) {
            $this->throwDuplicateRegistrationException();
        }

        $existing = $this->lockedRegistrationForOffering(
            $student->student_id,
            $courseOffering->course_offering_id
        );
        if ($existing !== null
            && $existing->registrationStatus?->status_code === StudentCourseRegistration::WITHDRAWN_STATUS) {
            throw RegistrationException::withdrawnNotReactivatable();
        }

        $academicStanding = $this->officialRegistrationAcademicStanding($student);
        if ($this->hasPassedCourse($student, (int) $courseOffering->course_id, $academicStanding)) {
            throw RegistrationException::courseAlreadyPassed();
        }

        $missingPrerequisites = $this->getMissingPrerequisites(
            $student,
            (int) $courseOffering->course_id,
            $academicStanding,
        );
        if ($missingPrerequisites !== []) {
            $labels = collect($missingPrerequisites)
                ->map(fn (array $course): string => $course['course_code'].' - '.$course['course_name'])
                ->implode(', ');

            throw new RegistrationException(
                'Student has missing prerequisites: '.$labels.'.',
                ['course_offering_id' => ['Missing prerequisites: '.$labels.'.']]
            );
        }

        $courseCreditHours = (int) ($courseOffering->course?->credit_hours ?? 0);
        $hours = $this->getHoursSnapshot(
            $student,
            (int) $courseOffering->academic_year_id,
            (int) $courseOffering->semester_id,
            $academicStanding,
        );

        if (($hours['registered_hours'] + $courseCreditHours) > $hours['max_allowed_hours']) {
            throw new RegistrationException('Credit hour limit exceeded for this academic term.', [
                'course_offering_id' => ['Credit hour limit exceeded for this academic term.'],
            ]);
        }

        $this->requirements->assertRegistrationCandidateAllowed($student, $courseOffering);

        $registeredByUserId = $authenticatedUserId;
        if ($registeredByUserId === null) {
            throw new RegistrationException('registered_by_user_id is required when no authenticated user is available.', [
                'registered_by_user_id' => ['The registered by user field is required.'],
            ]);
        }

        $registeredStatusId = $this->registrationStatusId('registered');
        if ($registeredStatusId === null) {
            throw new ModelNotFoundException('Registration status "registered" was not found.');
        }

        // Direct/student materialization always receives a fresh evaluation
        // after locking the authoritative Offering. The advisor entry point
        // independently re-locks and validates its submitted request proof and
        // deadlines before it can reach this shared persistence core.
        if ($context === RegistrationMaterializationContext::STUDENT_WINDOW) {
            $this->assertCourseRegistrationStudentWindowOpen(
                (int) $courseOffering->academic_year_id,
                (int) $courseOffering->semester_id,
            );
        }

        $timetable = $this->schedules->registrationEvaluations(
            $student,
            collect([$courseOffering]),
            $this->currentRegisteredOfferingIds($student),
            [],
        )[(int) $courseOffering->course_offering_id];
        $this->assertTimetableEvaluation($timetable);

        $registrationDate = $data['registration_date'] ?? now()->toDateString();
        $reactivatable = $this->findReactivatableRegistration(
            $student->student_id,
            $courseOffering->course_offering_id
        );

        if ($reactivatable !== null) {
            $reactivatable->update([
                'registration_date' => $registrationDate,
                'registered_by_user_id' => $registeredByUserId,
                'advisor_user_id' => $data['advisor_user_id'] ?? null,
                'registration_status_id' => $registeredStatusId,
                'result_status_id' => null,
                'notes' => $data['notes'] ?? null,
            ]);
            $registration = $reactivatable->fresh();
        } else {
            $registration = StudentCourseRegistration::query()->create([
                'student_id' => $student->student_id,
                'course_offering_id' => $courseOffering->course_offering_id,
                'registration_date' => $registrationDate,
                'registered_by_user_id' => $registeredByUserId,
                'advisor_user_id' => $data['advisor_user_id'] ?? null,
                'registration_status_id' => $registeredStatusId,
                'result_status_id' => null,
                'notes' => $data['notes'] ?? null,
            ]);
        }

        $registration->load([
            'student',
            'courseOffering.course',
            'courseOffering.academicYear',
            'courseOffering.semester',
            'registrationStatus',
        ]);

        $updatedHours = $this->getHoursSnapshot(
            $student,
            (int) $courseOffering->academic_year_id,
            (int) $courseOffering->semester_id,
            $academicStanding,
        );

        return [
            'registration' => $registration,
            'registered_hours' => $updatedHours['registered_hours'],
            'max_allowed_hours' => $updatedHours['max_allowed_hours'],
            'remaining_hours' => $updatedHours['remaining_hours'],
        ];
    }

    private function registrationExists(int $studentId, int $courseOfferingId): bool
    {
        return StudentCourseRegistration::query()
            ->where('student_id', $studentId)
            ->where('course_offering_id', $courseOfferingId)
            ->whereHas(
                'registrationStatus',
                fn (Builder $query) => $query->where('status_code', StudentCourseRegistration::CURRENT_STATUS)
            )
            ->exists();
    }

    private function findReactivatableRegistration(int $studentId, int $courseOfferingId): ?StudentCourseRegistration
    {
        return StudentCourseRegistration::query()
            ->with('registrationStatus')
            ->where('student_id', $studentId)
            ->where('course_offering_id', $courseOfferingId)
            ->whereHas(
                'registrationStatus',
                fn (Builder $query) => $query->whereIn('status_code', StudentCourseRegistration::REACTIVATABLE_STATUSES)
            )
            ->first();
    }

    private function lockedRegistrationForOffering(int $studentId, int $courseOfferingId): ?StudentCourseRegistration
    {
        return StudentCourseRegistration::query()
            ->with('registrationStatus')
            ->where('student_id', $studentId)
            ->where('course_offering_id', $courseOfferingId)
            ->orderBy('student_course_registration_id')
            ->first();
    }

    private function isDuplicateRegistrationQueryException(QueryException $exception): bool
    {
        $errorCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = $exception->getMessage();

        return $errorCode === 1062
            || str_contains($message, 'uq_student_course_offering');
    }

    private function throwDuplicateRegistrationException(): never
    {
        throw new RegistrationException('Student is already registered in this course offering.', [
            'course_offering_id' => ['Student is already registered in this course offering.'],
        ]);
    }

    public function dropRegistration(StudentCourseRegistration $registration): StudentCourseRegistration
    {
        throw RegistrationException::liveWorkflowRequired();
    }

    public function withdrawRegistration(StudentCourseRegistration $registration): StudentCourseRegistration
    {
        throw RegistrationException::liveWorkflowRequired();
    }

    public function selfDrop(Student $student, StudentCourseRegistration $registration): StudentCourseRegistration
    {
        return DB::transaction(function () use ($student, $registration): StudentCourseRegistration {
            $this->lockStudent((int) $student->student_id);
            $offering = $this->lockOffering((int) $registration->course_offering_id);
            $locked = $this->lockRegistration((int) $registration->student_course_registration_id);
            $locked->load(['registrationStatus', 'courseOffering']);

            if ((int) $locked->student_id !== (int) $student->student_id) {
                throw RegistrationException::notOwned();
            }

            $statusCode = $locked->registrationStatus?->status_code;
            if (in_array($statusCode, [
                StudentCourseRegistration::DROPPED_STATUS,
                StudentCourseRegistration::WITHDRAWN_STATUS,
            ], true)) {
                throw RegistrationException::notCurrent();
            }

            if ($statusCode !== StudentCourseRegistration::CURRENT_STATUS) {
                throw RegistrationException::notCurrent();
            }

            if ($offering->status !== 'open') {
                throw RegistrationException::selfDropClosed();
            }

            $this->transitionRegisteredToDropped($locked, $offering);

            return $locked->fresh()->load([
                'student',
                'courseOffering.course',
                'courseOffering.academicYear',
                'courseOffering.semester',
                'registrationStatus',
                'resultStatus',
            ]);
        });
    }

    public function transitionRegisteredToDropped(
        StudentCourseRegistration $lockedRegistration,
        CourseOffering $lockedOffering
    ): void {
        SupplementaryExamTargetGuard::assertAvailable((int) $lockedRegistration->getKey());
        SupplementaryExamTargetGuard::assertFixedRosterAvailable((int) $lockedRegistration->getKey());
        $this->assertLockedRegistrationIsRegistered($lockedRegistration);
        $droppedStatusId = $this->registrationStatusId(StudentCourseRegistration::DROPPED_STATUS);
        if ($droppedStatusId === null) {
            throw new ModelNotFoundException('Registration status "dropped" was not found.');
        }

        $lockedRegistration->update(['registration_status_id' => $droppedStatusId]);
    }

    public function transitionRegisteredToWithdrawn(
        StudentCourseRegistration $lockedRegistration,
        CourseOffering $lockedOffering
    ): void {
        SupplementaryExamTargetGuard::assertAvailable((int) $lockedRegistration->getKey());
        SupplementaryExamTargetGuard::assertFixedRosterAvailable((int) $lockedRegistration->getKey());
        $this->assertLockedRegistrationIsRegistered($lockedRegistration);
        $withdrawnStatusId = $this->registrationStatusId(StudentCourseRegistration::WITHDRAWN_STATUS);
        if ($withdrawnStatusId === null) {
            throw new ModelNotFoundException('Registration status "withdrawn" was not found.');
        }

        $lockedRegistration->update(['registration_status_id' => $withdrawnStatusId]);
    }

    public function lockStudent(int $studentId): Student
    {
        $student = Student::query()->whereKey($studentId)->lockForUpdate()->first();
        if ($student === null) {
            throw new RegistrationException('The selected student does not exist.', [
                'student_id' => ['The selected student does not exist.'],
            ]);
        }

        return $student;
    }

    public function lockOffering(int $offeringId): CourseOffering
    {
        $offering = CourseOffering::query()->whereKey($offeringId)->lockForUpdate()->first();
        if ($offering === null) {
            throw new RegistrationException('The selected course offering does not exist.', [
                'course_offering_id' => ['The selected course offering does not exist.'],
            ]);
        }

        return $offering;
    }

    public function lockRegistration(int $registrationId): StudentCourseRegistration
    {
        return StudentCourseRegistration::query()
            ->with('registrationStatus')
            ->whereKey($registrationId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function getRegisteredHours(Student $student, int $academicYearId, int $semesterId): array
    {
        $hours = $this->getHoursSnapshot($student, $academicYearId, $semesterId);

        return [
            'student_id' => $student->student_id,
            'academic_year_id' => $academicYearId,
            'semester_id' => $semesterId,
            'registered_hours' => $hours['registered_hours'],
            'official_cgpa' => $hours['official_cgpa'],
            'max_allowed_hours' => $hours['max_allowed_hours'],
            'remaining_hours' => $hours['remaining_hours'],
            'recommended_minimum_hours' => $hours['recommended_minimum_hours'],
        ];
    }

    public function getRegistrationSummary(Student $student, ?int $academicYearId = null, ?int $semesterId = null): array
    {
        $student->load(['currentAcademicLevel', 'studentStatus', 'academicProgram']);

        $registrationsQuery = $student->studentCourseRegistrations()
            ->with([
                'courseOffering.course',
                'courseOffering.academicYear',
                'courseOffering.semester',
                'registrationStatus',
            ])
            ->current();

        if ($academicYearId !== null) {
            $registrationsQuery->whereHas(
                'courseOffering',
                fn (Builder $query) => $query->where('academic_year_id', $academicYearId)
            );
        }

        if ($semesterId !== null) {
            $registrationsQuery->whereHas(
                'courseOffering',
                fn (Builder $query) => $query->where('semester_id', $semesterId)
            );
        }

        $registrations = $registrationsQuery
            ->orderBy('student_course_registration_id')
            ->get();
        $registrations->each(
            fn (StudentCourseRegistration $registration) => $registration->setRelation('student', $student)
        );
        $registrationOfferings = $registrations
            ->pluck('courseOffering')
            ->filter()
            ->unique('course_offering_id')
            ->values();
        $registrationSchedules = $this->schedules->describeMany($registrationOfferings);
        $registrationOfferings->each(function (CourseOffering $offering) use ($registrationSchedules): void {
            $offering->setAttribute(
                'official_timetable',
                $registrationSchedules[(int) $offering->course_offering_id] ?? null,
            );
        });

        $resolvedYearId = $academicYearId ?? (int) ($registrations->first()?->courseOffering?->academic_year_id ?? 0);
        $resolvedSemesterId = $semesterId ?? (int) ($registrations->first()?->courseOffering?->semester_id ?? 0);
        $academicStanding = $this->officialRegistrationAcademicStanding($student);

        $hours = $resolvedYearId > 0 && $resolvedSemesterId > 0
            ? $this->getHoursSnapshot($student, $resolvedYearId, $resolvedSemesterId, $academicStanding)
            : [
                'registered_hours' => 0,
                'official_cgpa' => $academicStanding['official_cgpa'],
                'max_allowed_hours' => $academicStanding['max_allowed_hours'],
                'remaining_hours' => $academicStanding['max_allowed_hours'],
                'recommended_minimum_hours' => self::RECOMMENDED_MINIMUM_CREDIT_HOURS,
            ];

        $academicYear = $academicYearId !== null
            ? AcademicYear::query()->find($academicYearId)
            : $registrations->first()?->courseOffering?->academicYear;

        $semester = $semesterId !== null
            ? Semester::query()->find($semesterId)
            : $registrations->first()?->courseOffering?->semester;

        return [
            'student' => $student,
            'academic_year' => $academicYear,
            'semester' => $semester,
            'academic_year_id' => $academicYearId ?? ($resolvedYearId > 0 ? $resolvedYearId : null),
            'semester_id' => $semesterId ?? ($resolvedSemesterId > 0 ? $resolvedSemesterId : null),
            'total_registered_courses' => $registrations->count(),
            'total_registered_hours' => $hours['registered_hours'],
            'official_cgpa' => $hours['official_cgpa'],
            'max_allowed_hours' => $hours['max_allowed_hours'],
            'remaining_hours' => $hours['remaining_hours'],
            'recommended_minimum_hours' => $hours['recommended_minimum_hours'],
            'registrations' => $registrations,
        ];
    }

    public function getAvailableCourses(Student $student, ?int $academicYearId = null, ?int $semesterId = null): Collection
    {
        $query = CourseOffering::query()
            ->with([
                'course',
                'academicYear',
                'semester',
                'department',
                'academicProgram',
                'facultyMember',
            ])
            ->where('status', 'open')
            ->where(function (Builder $builder) use ($student): void {
                $builder->whereNull('academic_program_id')
                    ->orWhere('academic_program_id', $student->academic_program_id);
            });

        if ($academicYearId !== null) {
            $query->where('academic_year_id', $academicYearId);
        }

        if ($semesterId !== null) {
            $query->where('semester_id', $semesterId);
        }

        $offerings = $query->orderBy('course_offering_id')->get();
        CourseRequirementClassification::classifyStudentOfferings(
            $student->academic_program_id === null ? null : (int) $student->academic_program_id,
            $offerings
        );

        $registeredOfferingIds = $this->currentRegisteredOfferingIds($student);
        $academicStanding = $this->officialRegistrationAcademicStanding($student);

        $hours = ($academicYearId !== null && $semesterId !== null)
            ? $this->getHoursSnapshot($student, $academicYearId, $semesterId, $academicStanding)
            : null;

        $requirementContext = $this->requirements->buildRegistrationCommitmentContext($student);
        $timetable = $this->schedules->registrationEvaluations(
            $student,
            $offerings,
            $registeredOfferingIds,
            [],
        );

        return $offerings->map(function (CourseOffering $offering) use ($student, $registeredOfferingIds, $hours, $requirementContext, $academicStanding, $timetable): CourseOffering {
            return $this->annotateOfferingEligibility(
                $offering,
                $student,
                $registeredOfferingIds,
                $hours,
                requirementContext: $requirementContext,
                academicStanding: $academicStanding,
                timetable: $timetable[(int) $offering->course_offering_id] ?? null,
            );
        });
    }

    public function registrationsForStudent(Student $student, int $perPage = 15): LengthAwarePaginator
    {
        return StudentCourseRegistration::query()
            ->with(['courseOffering.course', 'courseOffering.academicYear', 'courseOffering.semester', 'registrationStatus', 'resultStatus'])
            ->where('student_id', $student->student_id)
            ->latest('student_course_registration_id')
            ->paginate($perPage);
    }

    public function registrationsForCourseOffering(CourseOffering $courseOffering, int $perPage = 15): LengthAwarePaginator
    {
        return StudentCourseRegistration::query()
            ->with(['student', 'registrationStatus', 'resultStatus'])
            ->where('course_offering_id', $courseOffering->course_offering_id)
            ->latest('student_course_registration_id')
            ->paginate($perPage);
    }

    public function getMissingPrerequisites(
        Student $student,
        int $courseId,
        ?array $academicStanding = null,
    ): array
    {
        $academicStanding ??= $this->officialRegistrationAcademicStanding($student);
        $prerequisites = CoursePrerequisite::query()
            ->with('prerequisiteCourse')
            ->where('course_id', $courseId)
            ->get();

        $missing = [];

        foreach ($prerequisites as $prerequisite) {
            if (! $this->hasPassedCourse(
                $student,
                (int) $prerequisite->prerequisite_course_id,
                $academicStanding,
            )) {
                $course = $prerequisite->prerequisiteCourse;
                $missing[] = [
                    'course_id' => $prerequisite->prerequisite_course_id,
                    'course_code' => $course?->course_code,
                    'course_name' => $course?->course_name,
                ];
            }
        }

        return $missing;
    }

    public function hasPassedCourse(
        Student $student,
        int $courseId,
        ?array $academicStanding = null,
    ): bool
    {
        $academicStanding ??= $this->officialRegistrationAcademicStanding($student);

        return in_array($courseId, $academicStanding['official_passed_course_ids'], true);
    }

    public function getSelfRegistrationOfferings(
        Student $student,
        int $academicYearId,
        int $semesterId,
        int $pendingRequestHours = 0,
        array $requestOfferingIds = []
    ): Collection
    {
        $query = CourseOffering::query()
            ->with([
                'course',
                'academicYear',
                'semester',
                'department',
                'academicProgram',
                'facultyMember.employee',
            ]);
        $this->constrainSelfRegistrationOfferings($query, $student);
        $query
            ->where('academic_year_id', $academicYearId)
            ->where('semester_id', $semesterId);

        $offerings = $query->orderBy('course_offering_id')->get();
        CourseRequirementClassification::classifyStudentOfferings(
            $student->academic_program_id === null ? null : (int) $student->academic_program_id,
            $offerings
        );
        $registeredOfferingIds = $this->currentRegisteredOfferingIds($student);
        $academicStanding = $this->officialRegistrationAcademicStanding($student);
        $hours = $this->getHoursSnapshot($student, $academicYearId, $semesterId, $academicStanding);
        $requirementContext = $this->requirements->buildRegistrationCommitmentContext($student);
        $timetable = $this->schedules->registrationEvaluations(
            $student,
            $offerings,
            $registeredOfferingIds,
            $requestOfferingIds,
        );

        return $offerings->map(function (CourseOffering $offering) use (
            $student,
            $registeredOfferingIds,
            $hours,
            $pendingRequestHours,
            $requestOfferingIds,
            $requirementContext,
            $academicStanding,
            $timetable,
        ): CourseOffering {
            return $this->annotateOfferingEligibility(
                $offering,
                $student,
                $registeredOfferingIds,
                $hours,
                $pendingRequestHours,
                $requestOfferingIds,
                $requirementContext,
                $academicStanding,
                $timetable[(int) $offering->course_offering_id] ?? null,
            );
        });
    }

    public function selfRegistrationOpenSemesters(Student $student, int $academicYearId): Collection
    {
        $semesterIds = $this->constrainSelfRegistrationOfferings(CourseOffering::query(), $student)
            ->where('academic_year_id', $academicYearId)
            ->whereNotNull('semester_id')
            ->distinct()
            ->pluck('semester_id');

        if ($semesterIds->isEmpty()) {
            return collect();
        }

        return Semester::query()
            ->whereIn('semester_id', $semesterIds)
            ->orderBy('semester_order')
            ->get();
    }

    public function assertSelfRegistrationAllowed(Student $student, CourseOffering $offering): void
    {
        if ($student->academic_program_id === null) {
            throw new RegistrationException('Student is not assigned to an academic program.', [
                'student_id' => ['Student is not assigned to an academic program.'],
            ]);
        }

        if ($offering->status !== 'open') {
            throw new RegistrationException('The selected course offering is not open for registration.', [
                'course_offering_id' => ['The selected course offering is not open for registration.'],
            ]);
        }

        if ($offering->academic_program_id === null
            || (int) $offering->academic_program_id !== (int) $student->academic_program_id) {
            throw new RegistrationException('The selected course offering is not available for this academic program.', [
                'course_offering_id' => ['The selected course offering is not available for this academic program.'],
            ]);
        }

        if ($offering->academic_year_id === null || $offering->semester_id === null) {
            throw new RegistrationException('The selected course offering does not belong to a complete academic term.', [
                'course_offering_id' => ['The selected course offering does not belong to a complete academic term.'],
            ]);
        }

        $currentYearId = app(AcademicTermResolver::class)->uniqueCurrentAcademicYearId();
        if ($currentYearId === null || (int) $offering->academic_year_id !== $currentYearId) {
            throw new RegistrationException('The selected course offering is not open for the current academic term.', [
                'course_offering_id' => ['The selected course offering is not open for the current academic term.'],
            ]);
        }

        if (! $this->courseIsOnActiveProgramCurriculum($student, (int) $offering->course_id)) {
            throw new RegistrationException(
                'The selected course is not part of the student current program curriculum.',
                ['course_offering_id' => [AcademicRequirementService::REASON_COURSE_OUTSIDE_CURRENT_CURRICULUM]],
                422,
                AcademicRequirementService::REASON_COURSE_OUTSIDE_CURRENT_CURRICULUM
            );
        }

        $visible = $this->constrainSelfRegistrationOfferings(
            CourseOffering::query()->whereKey($offering->course_offering_id),
            $student
        )->exists();

        if (! $visible) {
            throw new RegistrationException('The selected course offering is not available for student self-registration.', [
                'course_offering_id' => ['The selected course offering is not available for student self-registration.'],
            ]);
        }
    }

    public function assertSelfDropAllowed(Student $student, StudentCourseRegistration $registration): void
    {
        if ((int) $registration->student_id !== (int) $student->student_id) {
            throw RegistrationException::notOwned();
        }

        $registration->loadMissing(['registrationStatus', 'courseOffering']);

        if (in_array($registration->registrationStatus?->status_code, [
            StudentCourseRegistration::DROPPED_STATUS,
            StudentCourseRegistration::WITHDRAWN_STATUS,
        ], true)) {
            throw RegistrationException::notCurrent();
        }

        if ($registration->registrationStatus?->status_code !== StudentCourseRegistration::CURRENT_STATUS) {
            throw RegistrationException::notCurrent();
        }

        if ($registration->courseOffering?->status !== 'open') {
            throw RegistrationException::selfDropClosed();
        }
    }

    private function annotateOfferingEligibility(
        CourseOffering $offering,
        Student $student,
        array $registeredOfferingIds,
        ?array $hours,
        int $pendingRequestHours = 0,
        array $requestOfferingIds = [],
        ?array $requirementContext = null,
        ?array $academicStanding = null,
        ?array $timetable = null,
    ): CourseOffering {
        $academicStanding ??= $this->officialRegistrationAcademicStanding($student);
        $missing = $this->getMissingPrerequisites(
            $student,
            (int) $offering->course_id,
            $academicStanding,
        );
        $reasons = [];
        $courseCreditHours = (int) ($offering->course?->credit_hours ?? 0);
        $offeringId = (int) $offering->course_offering_id;
        $alreadyInRequest = in_array($offeringId, $requestOfferingIds, true);

        if (in_array($offeringId, $registeredOfferingIds, true)) {
            $reasons[] = 'already_registered';
        }

        if ($alreadyInRequest) {
            $reasons[] = 'already_in_request';
        }

        if ($this->hasPassedCourse($student, (int) $offering->course_id, $academicStanding)) {
            $reasons[] = RegistrationException::COURSE_ALREADY_PASSED;
        }

        if ($missing !== []) {
            $reasons[] = 'missing_prerequisites';
        }

        $committedHours = $hours === null ? null : ((int) $hours['registered_hours'] + max($pendingRequestHours, 0));
        if (
            $hours !== null
            && ! $alreadyInRequest
            && ($committedHours + $courseCreditHours) > $hours['max_allowed_hours']
        ) {
            $reasons[] = 'credit_limit_exceeded';
        }

        $evaluation = $this->requirements->evaluateRegistrationCandidate($student, $offering, $requirementContext);
        if (! $evaluation['allowed'] && is_string($evaluation['reason']) && $evaluation['reason'] !== '') {
            $reasons[] = $evaluation['reason'];
        }

        if ($timetable !== null && is_string($timetable['reason'] ?? null)) {
            $reasons[] = $timetable['reason'];
        }

        $offering->setAttribute('eligibility_status', $reasons === [] ? 'eligible' : 'not_eligible');
        $offering->setAttribute('eligibility_reasons', array_values(array_unique($reasons)));
        $offering->setAttribute('missing_prerequisites', $missing);
        $offering->setAttribute('official_timetable', $timetable['schedule'] ?? null);
        $offering->setAttribute('timetable_conflicts', $timetable['conflicts'] ?? []);
        $offering->setAttribute('incomplete_timetable_sources', $timetable['incomplete_timetable_sources'] ?? []);

        return $offering;
    }

    public function timetableEvaluations(
        Student $student,
        Collection $offerings,
        array $officialOfferingIds,
        array $requestOfferingIds,
    ): array {
        return $this->schedules->registrationEvaluations(
            $student,
            $offerings,
            $officialOfferingIds,
            $requestOfferingIds,
        );
    }

    private function assertTimetableEvaluation(array $evaluation): void
    {
        $reason = $evaluation['reason'] ?? null;
        if ($reason === null) {
            return;
        }
        if ($reason === RegistrationException::TIMETABLE_SCHEMA_NOT_READY) {
            throw RegistrationException::timetableSchemaNotReady();
        }
        if ($reason === RegistrationException::OFFERING_SCHEDULE_INCOMPLETE) {
            throw RegistrationException::offeringScheduleIncomplete($evaluation['schedule'] ?? []);
        }
        if ($reason === RegistrationException::TIMETABLE_CONFLICT) {
            throw RegistrationException::timetableConflict($evaluation['conflicts'] ?? []);
        }
        if ($reason === RegistrationException::TIMETABLE_REFERENCE_INCOMPLETE) {
            throw RegistrationException::timetableReferenceIncomplete($evaluation['incomplete_timetable_sources'] ?? []);
        }
    }

    private function constrainSelfRegistrationOfferings(Builder $query, Student $student): Builder
    {
        if ($student->academic_program_id === null) {
            return $query->whereRaw('1 = 0');
        }

        $programId = (int) $student->academic_program_id;
        $query
            ->where('course_offerings.status', 'open')
            ->whereNotNull('course_offerings.academic_program_id')
            ->where('course_offerings.academic_program_id', $programId);

        $curriculumCourseIds = ProgramCourse::query()
            ->where('academic_program_id', $programId)
            ->where('is_active', true)
            ->pluck('course_id');

        if ($curriculumCourseIds->isNotEmpty()) {
            $query->whereIn('course_offerings.course_id', $curriculumCourseIds);
        }

        return $query;
    }

    private function courseIsOnActiveProgramCurriculum(Student $student, int $courseId): bool
    {
        if ($student->academic_program_id === null) {
            return false;
        }

        $programId = (int) $student->academic_program_id;
        $hasCurriculum = ProgramCourse::query()
            ->where('academic_program_id', $programId)
            ->where('is_active', true)
            ->exists();

        if (! $hasCurriculum) {
            return true;
        }

        return ProgramCourse::query()
            ->where('academic_program_id', $programId)
            ->where('course_id', $courseId)
            ->where('is_active', true)
            ->exists();
    }

    private function currentRegisteredOfferingIds(Student $student): array
    {
        return StudentCourseRegistration::query()
            ->where('student_id', $student->student_id)
            ->current()
            ->pluck('course_offering_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function getHoursSnapshot(
        Student $student,
        int $academicYearId,
        int $semesterId,
        ?array $academicStanding = null,
    ): array
    {
        $academicStanding ??= $this->officialRegistrationAcademicStanding($student);
        $registeredHours = (int) StudentCourseRegistration::query()
            ->join('course_offerings', 'course_offerings.course_offering_id', '=', 'student_course_registrations.course_offering_id')
            ->join('courses', 'courses.course_id', '=', 'course_offerings.course_id')
            ->join('registration_statuses', 'registration_statuses.registration_status_id', '=', 'student_course_registrations.registration_status_id')
            ->where('student_course_registrations.student_id', $student->student_id)
            ->where('course_offerings.academic_year_id', $academicYearId)
            ->where('course_offerings.semester_id', $semesterId)
            ->where('registration_statuses.status_code', StudentCourseRegistration::CURRENT_STATUS)
            ->sum('courses.credit_hours');

        $maxAllowedHours = (int) $academicStanding['max_allowed_hours'];

        return [
            'registered_hours' => $registeredHours,
            'official_cgpa' => $academicStanding['official_cgpa'],
            'max_allowed_hours' => $maxAllowedHours,
            'remaining_hours' => max($maxAllowedHours - $registeredHours, 0),
            'recommended_minimum_hours' => self::RECOMMENDED_MINIMUM_CREDIT_HOURS,
            'official_passed_course_ids' => $academicStanding['official_passed_course_ids'],
        ];
    }

    /**
     * Registration-facing projection of GradeService's canonical official
     * cumulative metrics. No GPA or grade-approval mathematics lives here.
     *
     * @return array{official_cgpa: ?float, max_allowed_hours: int, official_passed_course_ids: list<int>}
     */
    private function officialRegistrationAcademicStanding(Student $student): array
    {
        $metrics = $this->grades->officialCumulativeMetrics($student);
        $canonicalCgpa = $metrics['cumulative_gpa'] ?? null;
        $cgpa = $canonicalCgpa === null
            ? null
            : (float) $canonicalCgpa;
        $passedCourseIds = collect($metrics['official_completed_courses'] ?? [])
            ->pluck('course_id')
            ->filter(fn ($courseId): bool => $courseId !== null)
            ->map(fn ($courseId): int => (int) $courseId)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'official_cgpa' => $cgpa,
            'max_allowed_hours' => $cgpa !== null && $cgpa >= self::HIGH_CGPA_THRESHOLD
                ? self::HIGH_CGPA_MAX_CREDIT_HOURS
                : self::DEFAULT_MAX_CREDIT_HOURS,
            'official_passed_course_ids' => $passedCourseIds,
        ];
    }

    private function registrationStatusId(string $statusCode): ?int
    {
        return RegistrationStatus::query()
            ->where('status_code', $statusCode)
            ->value('registration_status_id');
    }

    private function assertLockedRegistrationIsRegistered(StudentCourseRegistration $registration): void
    {
        $registration->loadMissing('registrationStatus');
        if ($registration->registrationStatus?->status_code !== StudentCourseRegistration::CURRENT_STATUS) {
            throw RegistrationException::notCurrent();
        }
    }

}
