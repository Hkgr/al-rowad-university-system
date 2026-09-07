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
use App\Support\SemesterOfferingGovernance;
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
        return $this->persistClosedOffering(array_merge($context->offeringAttributes(), $attributes));
    }

    /** Recording evidence, not enrollment eligibility or an invented historical curriculum. */
    public function resolveManualRecordingIdentity(\App\Models\Student $student, Course $course, int $yearId, int $semesterId, User $actor): array
    {
        app(\App\Support\ExamManualGradeEntryAccess::class)->authorize($actor, $student);
        abort_unless($this->dataScope->scopeManualGradeCourses(Course::query(), $actor)->whereKey($course->getKey())->exists(), 403);
        $program = AcademicProgram::with('department.college')->find($student->academic_program_id);
        abort_unless($program && $this->dataScope->canMutateProgram($actor, $program), 403);
        if (!$program->department?->college) throw CourseOfferingContextException::programContextIncomplete();
        AcademicYear::findOrFail($yearId);
        Semester::findOrFail($semesterId);
        // Inactive membership is persisted evidence too. No classifications or dates are inferred.
        $membershipIds = ProgramCourse::where('academic_program_id', $program->getKey())->where('course_id', $course->getKey())
            ->orderBy('program_course_id')->pluck('program_course_id')->all();
        $attemptIds = StudentCourseRegistration::where('student_id', $student->getKey())
            ->whereHas('courseOffering', fn ($q) => $q->where('course_id', $course->getKey())->where('academic_program_id', $program->getKey()))
            ->orderBy('student_course_registration_id')->pluck('student_course_registration_id')->all();
        if ($membershipIds === [] && $attemptIds === []) {
            throw new \App\Exceptions\GradeException('لا توجد علاقة برنامج محفوظة أو محاولة سابقة تربط هذا المقرر ببرنامج الطالب.', status: 409, errorCode: 'manual_recording_relationship_missing');
        }
        return ['attributes' => ['course_id' => (int) $course->getKey(), 'academic_program_id' => (int) $program->getKey(),
            'department_id' => (int) $program->department_id, 'academic_year_id' => $yearId, 'semester_id' => $semesterId],
            'evidence' => ['program_course_ids' => $membershipIds, 'registration_ids' => $attemptIds]];
    }

    public function createManualRecordingOffering(\App\Models\Student $student, Course $course, int $yearId, int $semesterId, User $actor): CourseOffering
    {
        $identity = $this->resolveManualRecordingIdentity($student, $course, $yearId, $semesterId, $actor);
        return $this->persistClosedOffering($identity['attributes']);
    }

    private function persistClosedOffering(array $attributes): CourseOffering
    {
        try {
            $payload = $attributes;
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
     * Offering-first lock, then current registered rows. This maintains the
     * legacy administrative capacity snapshot only; registration eligibility
     * and lifecycle transitions neither consume nor mutate available_seats.
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
        // Governance history and every already-open Offering make the
        // operational identity immutable. Legacy OPEN rows stay readable;
        // no governance row is fabricated for them.
        if ((SemesterOfferingGovernance::schemaReady()
                && $offering->semesterOfferingRequest()->exists())
            || (string) $offering->status === CourseOfferingOpeningService::STATUS_OPEN) {
            return true;
        }

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

    public function assertIdentityChangeAllowed(CourseOffering $offering, int $courseId, int $programId, int $yearId, int $semesterId): void
    {
        if ($this->identityWouldChange($offering, $courseId, $programId, $yearId, $semesterId)
            && $this->hasHistoricalDependents($offering)) {
            throw CourseOfferingContextException::identityLocked();
        }
    }
}
