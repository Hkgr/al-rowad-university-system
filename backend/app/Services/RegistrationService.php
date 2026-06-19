<?php

namespace App\Services;

use App\Exceptions\RegistrationException;
use App\Models\AcademicYear;
use App\Models\CourseOffering;
use App\Models\CoursePrerequisite;
use App\Models\RegistrationStatus;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentCourseRegistration;
use App\Models\StudentCreditLimit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RegistrationService
{
    private const DEFAULT_MAX_CREDIT_HOURS = 18;

    private const SEAT_OCCUPYING_STATUS = 'registered';

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
        try {
            return DB::transaction(function () use ($data, $authenticatedUserId): array {
                return $this->performRegisterStudent($data, $authenticatedUserId);
            });
        } catch (QueryException $exception) {
            if ($this->isDuplicateRegistrationQueryException($exception)) {
                $this->throwDuplicateRegistrationException();
            }

            throw $exception;
        }
    }

    private function performRegisterStudent(array $data, ?int $authenticatedUserId): array
    {
        $student = Student::query()->find($data['student_id']);
        if ($student === null) {
            throw new RegistrationException('The selected student does not exist.', [
                'student_id' => ['The selected student does not exist.'],
            ]);
        }

        $courseOffering = CourseOffering::query()
            ->with('course')
            ->lockForUpdate()
            ->find($data['course_offering_id']);

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

        if ($this->registrationExists($student->student_id, $courseOffering->course_offering_id)) {
            $this->throwDuplicateRegistrationException();
        }

        if ((int) $courseOffering->available_seats <= 0) {
            throw new RegistrationException('No available seats remain for the selected course offering.', [
                'course_offering_id' => ['No available seats remain for the selected course offering.'],
            ]);
        }

        $missingPrerequisites = $this->getMissingPrerequisites($student, (int) $courseOffering->course_id);
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
            (int) $courseOffering->semester_id
        );

        if (($hours['registered_hours'] + $courseCreditHours) > $hours['max_allowed_hours']) {
            throw new RegistrationException('Credit hour limit exceeded for this academic term.', [
                'course_offering_id' => ['Credit hour limit exceeded for this academic term.'],
            ]);
        }

        $registeredByUserId = $data['registered_by_user_id'] ?? $authenticatedUserId;
        if ($registeredByUserId === null) {
            throw new RegistrationException('registered_by_user_id is required when no authenticated user is available.', [
                'registered_by_user_id' => ['The registered by user field is required.'],
            ]);
        }

        $registeredStatusId = $this->registrationStatusId('registered');
        if ($registeredStatusId === null) {
            throw new ModelNotFoundException('Registration status "registered" was not found.');
        }

        $registration = StudentCourseRegistration::query()->create([
            'student_id' => $student->student_id,
            'course_offering_id' => $courseOffering->course_offering_id,
            'registration_date' => $data['registration_date'] ?? now()->toDateString(),
            'registered_by_user_id' => $registeredByUserId,
            'advisor_user_id' => $data['advisor_user_id'] ?? null,
            'registration_status_id' => $registeredStatusId,
            'result_status_id' => null,
            'notes' => $data['notes'] ?? null,
        ]);

        $courseOffering->decrement('available_seats');
        $courseOffering->refresh();

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
            (int) $courseOffering->semester_id
        );

        return [
            'registration' => $registration,
            'registered_hours' => $updatedHours['registered_hours'],
            'max_allowed_hours' => $updatedHours['max_allowed_hours'],
            'remaining_hours' => $updatedHours['remaining_hours'],
            'available_seats' => (int) $courseOffering->available_seats,
        ];
    }

    private function registrationExists(int $studentId, int $courseOfferingId): bool
    {
        return StudentCourseRegistration::query()
            ->where('student_id', $studentId)
            ->where('course_offering_id', $courseOfferingId)
            ->exists();
    }

    private function isDuplicateRegistrationQueryException(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $errorCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = $exception->getMessage();

        return $sqlState === '23000'
            || $errorCode === 1062
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
        return DB::transaction(function () use ($registration): StudentCourseRegistration {
            $registration = StudentCourseRegistration::query()
                ->with(['registrationStatus', 'courseOffering'])
                ->lockForUpdate()
                ->findOrFail($registration->student_course_registration_id);

            $statusCode = $registration->registrationStatus?->status_code;

            if ($statusCode === 'dropped') {
                throw new RegistrationException('Registration is already dropped.');
            }

            $droppedStatusId = $this->registrationStatusId('dropped');
            if ($droppedStatusId === null) {
                throw new ModelNotFoundException('Registration status "dropped" was not found.');
            }

            if ($statusCode === self::SEAT_OCCUPYING_STATUS) {
                CourseOffering::query()
                    ->whereKey($registration->course_offering_id)
                    ->lockForUpdate()
                    ->first()
                    ?->increment('available_seats');
            }

            $registration->update(['registration_status_id' => $droppedStatusId]);

            return $registration->fresh()->load([
                'student',
                'courseOffering.course',
                'courseOffering.academicYear',
                'courseOffering.semester',
                'registrationStatus',
                'resultStatus',
            ]);
        });
    }

    public function withdrawRegistration(StudentCourseRegistration $registration): StudentCourseRegistration
    {
        return DB::transaction(function () use ($registration): StudentCourseRegistration {
            $registration = StudentCourseRegistration::query()
                ->with(['registrationStatus', 'courseOffering'])
                ->lockForUpdate()
                ->findOrFail($registration->student_course_registration_id);

            $statusCode = $registration->registrationStatus?->status_code;

            if ($statusCode === 'withdrawn') {
                throw new RegistrationException('Registration is already withdrawn.');
            }

            if ($statusCode === 'dropped') {
                throw new RegistrationException('Registration is already dropped.');
            }

            $withdrawnStatusId = $this->registrationStatusId('withdrawn');
            if ($withdrawnStatusId === null) {
                throw new ModelNotFoundException('Registration status "withdrawn" was not found.');
            }

            if ($statusCode === self::SEAT_OCCUPYING_STATUS) {
                CourseOffering::query()
                    ->whereKey($registration->course_offering_id)
                    ->lockForUpdate()
                    ->first()
                    ?->increment('available_seats');
            }

            $registration->update(['registration_status_id' => $withdrawnStatusId]);

            return $registration->fresh()->load([
                'student',
                'courseOffering.course',
                'courseOffering.academicYear',
                'courseOffering.semester',
                'registrationStatus',
                'resultStatus',
            ]);
        });
    }

    public function getRegisteredHours(Student $student, int $academicYearId, int $semesterId): array
    {
        $hours = $this->getHoursSnapshot($student, $academicYearId, $semesterId);

        return [
            'student_id' => $student->student_id,
            'academic_year_id' => $academicYearId,
            'semester_id' => $semesterId,
            'registered_hours' => $hours['registered_hours'],
            'max_allowed_hours' => $hours['max_allowed_hours'],
            'remaining_hours' => $hours['remaining_hours'],
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
            ->whereHas('registrationStatus', fn (Builder $query) => $query->where('status_code', self::SEAT_OCCUPYING_STATUS));

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

        $resolvedYearId = $academicYearId ?? (int) ($registrations->first()?->courseOffering?->academic_year_id ?? 0);
        $resolvedSemesterId = $semesterId ?? (int) ($registrations->first()?->courseOffering?->semester_id ?? 0);

        $hours = $resolvedYearId > 0 && $resolvedSemesterId > 0
            ? $this->getHoursSnapshot($student, $resolvedYearId, $resolvedSemesterId)
            : [
                'registered_hours' => 0,
                'max_allowed_hours' => self::DEFAULT_MAX_CREDIT_HOURS,
                'remaining_hours' => self::DEFAULT_MAX_CREDIT_HOURS,
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
            'max_allowed_hours' => $hours['max_allowed_hours'],
            'remaining_hours' => $hours['remaining_hours'],
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

        $registeredOfferingIds = StudentCourseRegistration::query()
            ->where('student_id', $student->student_id)
            ->whereHas('registrationStatus', fn (Builder $query) => $query->where('status_code', self::SEAT_OCCUPYING_STATUS))
            ->pluck('course_offering_id')
            ->all();

        $hours = ($academicYearId !== null && $semesterId !== null)
            ? $this->getHoursSnapshot($student, $academicYearId, $semesterId)
            : null;

        return $offerings->map(function (CourseOffering $offering) use ($student, $registeredOfferingIds, $hours): CourseOffering {
            $reasons = [];
            $courseCreditHours = (int) ($offering->course?->credit_hours ?? 0);

            if (in_array($offering->course_offering_id, $registeredOfferingIds, true)) {
                $reasons[] = 'already_registered';
            }

            if ($this->getMissingPrerequisites($student, (int) $offering->course_id) !== []) {
                $reasons[] = 'missing_prerequisites';
            }

            if ((int) $offering->available_seats <= 0) {
                $reasons[] = 'no_available_seats';
            }

            if ($hours !== null && ($hours['registered_hours'] + $courseCreditHours) > $hours['max_allowed_hours']) {
                $reasons[] = 'credit_limit_exceeded';
            }

            $offering->setAttribute('eligibility_status', $reasons === [] ? 'eligible' : 'not_eligible');
            $offering->setAttribute('eligibility_reasons', $reasons);

            return $offering;
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

    public function getMissingPrerequisites(Student $student, int $courseId): array
    {
        $prerequisites = CoursePrerequisite::query()
            ->with('prerequisiteCourse')
            ->where('course_id', $courseId)
            ->get();

        $missing = [];

        foreach ($prerequisites as $prerequisite) {
            if (! $this->hasPassedCourse($student, (int) $prerequisite->prerequisite_course_id)) {
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

    public function hasPassedCourse(Student $student, int $prerequisiteCourseId): bool
    {
        $registrations = StudentCourseRegistration::query()
            ->where('student_id', $student->student_id)
            ->whereHas('courseOffering', fn (Builder $query) => $query->where('course_id', $prerequisiteCourseId))
            ->with(['studentCourseResult.resultStatus', 'resultStatus'])
            ->get();

        foreach ($registrations as $registration) {
            $result = $registration->studentCourseResult;

            if ($result !== null) {
                if ($result->final_mark !== null && (float) $result->final_mark >= 50) {
                    return true;
                }

                if ($result->resultStatus?->status_code === 'passed') {
                    return true;
                }
            }

            if ($registration->resultStatus?->status_code === 'passed') {
                return true;
            }
        }

        return false;
    }

    private function getHoursSnapshot(Student $student, int $academicYearId, int $semesterId): array
    {
        $registeredHours = (int) StudentCourseRegistration::query()
            ->join('course_offerings', 'course_offerings.course_offering_id', '=', 'student_course_registrations.course_offering_id')
            ->join('courses', 'courses.course_id', '=', 'course_offerings.course_id')
            ->join('registration_statuses', 'registration_statuses.registration_status_id', '=', 'student_course_registrations.registration_status_id')
            ->where('student_course_registrations.student_id', $student->student_id)
            ->where('course_offerings.academic_year_id', $academicYearId)
            ->where('course_offerings.semester_id', $semesterId)
            ->where('registration_statuses.status_code', self::SEAT_OCCUPYING_STATUS)
            ->sum('courses.credit_hours');

        $maxAllowedHours = (int) (StudentCreditLimit::query()
            ->where('student_id', $student->student_id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester_id', $semesterId)
            ->max('max_credit_hours') ?? self::DEFAULT_MAX_CREDIT_HOURS);

        return [
            'registered_hours' => $registeredHours,
            'max_allowed_hours' => $maxAllowedHours,
            'remaining_hours' => max($maxAllowedHours - $registeredHours, 0),
        ];
    }

    private function registrationStatusId(string $statusCode): ?int
    {
        return RegistrationStatus::query()
            ->where('status_code', $statusCode)
            ->value('registration_status_id');
    }
}
