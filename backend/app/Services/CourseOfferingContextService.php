<?php

namespace App\Services;

use App\Exceptions\CourseOfferingClosureException;
use App\Exceptions\CourseOfferingContextException;
use App\Models\AcademicProgram;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\ProgramCourse;
use App\Models\Semester;
use App\Models\StudentCourseRegistration;
use App\Models\User;
use App\Support\CourseOfferingContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

class CourseOfferingContextService
{
    public const UNIQUE_INDEX = 'uq_course_offering_program_term';

    public function __construct(private DataScopeService $dataScope)
    {
    }

    public function resolveContext(
        int $courseId,
        int $academicProgramId,
        int $academicYearId,
        int $semesterId,
        ?int $departmentId = null,
        ?User $actor = null,
        bool $assertUnique = true,
        ?int $ignoreOfferingId = null,
    ): CourseOfferingContext {
        $program = AcademicProgram::query()
            ->with(['department.college'])
            ->find($academicProgramId);

        if ($program === null) {
            throw CourseOfferingContextException::programContextIncomplete();
        }

        $department = $program->department;
        $college = $department?->college;
        if ($department === null || $department->department_id === null || $college === null) {
            throw CourseOfferingContextException::programContextIncomplete();
        }

        $resolvedDepartmentId = (int) $department->department_id;
        if ($departmentId !== null && (int) $departmentId !== $resolvedDepartmentId) {
            throw CourseOfferingContextException::programDepartmentMismatch();
        }

        if ($actor !== null && ! $this->dataScope->canAccessProgram($actor, (int) $program->academic_program_id)) {
            throw CourseOfferingContextException::programOutsideUserScope();
        }

        $course = Course::query()->find($courseId);
        $year = AcademicYear::query()->find($academicYearId);
        $semester = Semester::query()->find($semesterId);
        if ($course === null || $year === null || $semester === null) {
            throw CourseOfferingContextException::programContextIncomplete();
        }

        $programCourse = ProgramCourse::query()
            ->where('academic_program_id', (int) $program->academic_program_id)
            ->where('course_id', (int) $course->course_id)
            ->where('is_active', true)
            ->first();

        if ($programCourse === null) {
            throw CourseOfferingContextException::courseNotInProgram();
        }

        $context = new CourseOfferingContext(
            $course,
            $programCourse,
            $program,
            $department,
            $college,
            $year,
            $semester,
        );

        if ($assertUnique) {
            $this->assertUniqueIdentity($context, $ignoreOfferingId);
        }

        return $context;
    }

    public function resolveFromProgramCourse(
        ProgramCourse $programCourse,
        int $academicYearId,
        int $semesterId,
        ?User $actor = null,
        bool $assertUnique = true,
        ?int $ignoreOfferingId = null,
    ): CourseOfferingContext {
        if (! $programCourse->is_active || $programCourse->course_id === null || $programCourse->academic_program_id === null) {
            throw CourseOfferingContextException::courseNotInProgram();
        }

        return $this->resolveContext(
            (int) $programCourse->course_id,
            (int) $programCourse->academic_program_id,
            $academicYearId,
            $semesterId,
            null,
            $actor,
            $assertUnique,
            $ignoreOfferingId,
        );
    }

    public function assertUniqueIdentity(CourseOfferingContext $context, ?int $ignoreOfferingId = null): void
    {
        $identity = $context->offeringAttributes();
        $query = CourseOffering::query()
            ->where('course_id', $identity['course_id'])
            ->where('academic_program_id', $identity['academic_program_id'])
            ->where('academic_year_id', $identity['academic_year_id'])
            ->where('semester_id', $identity['semester_id']);

        if ($ignoreOfferingId !== null) {
            $query->whereKeyNot($ignoreOfferingId);
        }

        if ($query->exists()) {
            throw CourseOfferingContextException::duplicate();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createOffering(CourseOfferingContext $context, array $attributes = []): CourseOffering
    {
        try {
            $payload = array_merge($context->offeringAttributes(), $attributes);
            // User-facing / dean opening paths must not assign an instructor.
            // Legacy faculty_member_id is synchronized only after dual VP approval.
            $payload['faculty_member_id'] = null;
            // New offerings cannot be created OPEN: instructor coverage requires an
            // existing offering_id for Phase 4 assignment requests. Persist closed
            // and let CourseOfferingOpeningService perform the later open.
            $payload['status'] = CourseOfferingOpeningService::STATUS_CLOSED;

            return CourseOffering::query()->create($payload);
        } catch (QueryException $exception) {
            if ($this->isDuplicateKey($exception)) {
                throw CourseOfferingContextException::duplicate();
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateOffering(CourseOffering $offering, array $attributes): CourseOffering
    {
        try {
            unset($attributes['faculty_member_id']);
            $this->applyCapacityChange($offering, $attributes);
            // Opening is an academic invariant owned by CourseOfferingOpeningService.
            // Generic update must not write status=open even for courses.manage / super_admin.
            if (array_key_exists('status', $attributes)
                && (string) $attributes['status'] === CourseOfferingOpeningService::STATUS_OPEN) {
                unset($attributes['status']);
            }
            // Semantic OPEN → CLOSED is owned by Phase 7 closure materialization.
            // CLOSED → CLOSED is not a transition and may be stripped so unrelated
            // metadata updates still succeed.
            if (array_key_exists('status', $attributes)
                && (string) $attributes['status'] === CourseOfferingOpeningService::STATUS_CLOSED) {
                if ((string) $offering->status === CourseOfferingOpeningService::STATUS_OPEN) {
                    throw CourseOfferingClosureException::workflowRequired();
                }
                unset($attributes['status']);
            }
            $offering->update($attributes);

            return $offering;
        } catch (QueryException $exception) {
            if ($this->isDuplicateKey($exception)) {
                throw CourseOfferingContextException::duplicate();
            }

            throw $exception;
        }
    }

    public function isDuplicateKey(QueryException $exception): bool
    {
        $errorCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = $exception->getMessage();

        return $errorCode === 1062
            || str_contains($message, self::UNIQUE_INDEX);
    }

    /**
     * Offering-first lock, then current registered rows. Compatible with
     * RegistrationService seat mutation. Client never supplies available_seats.
     *
     * occupied = current StudentCourseRegistration rows with status_code registered.
     * available_seats = new_capacity - occupied.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function applyCapacityChange(CourseOffering $offering, array &$attributes): void
    {
        unset($attributes['available_seats']);
        if (! array_key_exists('capacity', $attributes)) {
            return;
        }

        CourseOffering::query()
            ->whereKey($offering->course_offering_id)
            ->lockForUpdate()
            ->firstOrFail();

        $occupied = StudentCourseRegistration::query()
            ->where('course_offering_id', $offering->course_offering_id)
            ->current()
            ->orderBy('student_course_registration_id')
            ->lockForUpdate()
            ->count();
        // occupied current registrations = current registered rows (status_code registered).

        $newCapacity = (int) $attributes['capacity'];
        if ($newCapacity < $occupied) {
            throw CourseOfferingContextException::capacityBelowOccupied();
        }

        $attributes['capacity'] = $newCapacity;
        $attributes['available_seats'] = $newCapacity - $occupied;
    }

    public function hasHistoricalDependents(CourseOffering $offering): bool
    {
        return $offering->studentCourseRegistrations()->exists()
            || $offering->attendanceSessions()->exists()
            || $offering->gradeApprovals()->exists()
            || $offering->gradePartApprovals()->exists()
            || $offering->gradeComponents()->exists()
            || (Schema::hasTable('teaching_assignment_requests')
                && $offering->teachingAssignmentRequests()->exists());
    }

    public function identityWouldChange(CourseOffering $offering, int $courseId, int $programId, int $yearId, int $semesterId): bool
    {
        return (int) $offering->course_id !== $courseId
            || (int) ($offering->academic_program_id ?? 0) !== $programId
            || (int) $offering->academic_year_id !== $yearId
            || (int) $offering->semester_id !== $semesterId;
    }
}
