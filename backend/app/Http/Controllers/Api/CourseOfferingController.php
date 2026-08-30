<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CourseOfferingClosureException;
use App\Exceptions\CourseOfferingContextException;
use App\Exceptions\TeachingAssignmentException;
use App\Http\Requests\CourseOffering\StoreCourseOfferingRequest;
use App\Http\Requests\CourseOffering\UpdateCourseOfferingRequest;
use App\Http\Requests\Attendance\StoreCourseOfferingAttendanceSessionRequest;
use App\Http\Resources\CourseOfferingResource;
use App\Http\Resources\CourseOfferingStudentResource;
use App\Http\Resources\StudentCourseRegistrationResource;
use App\Models\CourseOffering;
use App\Services\AttendanceService;
use App\Services\CourseOfferingContextService;
use App\Services\CourseOfferingInstructorCoverageService;
use App\Services\CourseOfferingOpeningService;
use App\Services\GradeService;
use App\Services\AcademicAuthorizationService;
use App\Services\DataScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Http\FormRequest;

class CourseOfferingController extends ApiController
{
    public function __construct(
        private CourseOfferingContextService $offeringContext,
        private CourseOfferingOpeningService $opening,
    ) {
    }

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', CourseOffering::class);
        $offerings = app(DataScopeService::class)->scopeOfferings(CourseOffering::query(), request()->user())
            ->with($this->offeringRelations())
            ->paginate(request()->integer('per_page', 15));
        return $this->successResponse(CourseOfferingResource::collection($offerings)->response(request())->getData(true));
    }
    protected function modelClass(): string
    {
        return CourseOffering::class;
    }

    protected function resourceClass(): string
    {
        return CourseOfferingResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreCourseOfferingRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateCourseOfferingRequest::class;
    }

    public function store(): JsonResponse
    {
        Gate::authorize('create', CourseOffering::class);
        /** @var FormRequest $request */
        $request = app($this->storeRequestClass());
        $data = $request->validated();
        $context = $this->offeringContext->resolveContext(
            (int) $data['course_id'],
            (int) $data['academic_program_id'],
            (int) $data['academic_year_id'],
            (int) $data['semester_id'],
            isset($data['department_id']) ? (int) $data['department_id'] : null,
            $request->user(),
        );
        $this->rejectExternalFacultyMemberAssignment($request);
        $requestedOpen = (string) ($data['status'] ?? '') === CourseOfferingOpeningService::STATUS_OPEN;
        $offering = $this->offeringContext->createOffering($context, [
            'capacity' => $data['capacity'],
            'available_seats' => (int) $data['capacity'],
            'status' => CourseOfferingOpeningService::STATUS_CLOSED,
        ]);

        $message = $requestedOpen
            ? 'تم إنشاء طرح المادة مغلقًا. يجب استكمال تكليف المدرسين المعتمدين قبل فتحها.'
            : 'تم إنشاء طرح المادة. يجب استكمال تكليف المدرسين المعتمدين قبل فتحها.';

        return $this->successResponse(
            (new CourseOfferingResource($offering->load($this->offeringRelations())))->resolve($request),
            $message,
            201
        );
    }

    public function update($id): JsonResponse
    {
        $offering = CourseOffering::query()->findOrFail($id);
        Gate::authorize('update', $offering);
        /** @var FormRequest $request */
        $request = app($this->updateRequestClass());
        $this->rejectExternalFacultyMemberAssignment($request, $offering);
        $data = $request->validated();
        unset($data['faculty_member_id']);

        $requestedOpen = array_key_exists('status', $data)
            && (string) $data['status'] === CourseOfferingOpeningService::STATUS_OPEN;
        $requestedClosed = array_key_exists('status', $data)
            && (string) $data['status'] === CourseOfferingOpeningService::STATUS_CLOSED;

        $offering = $this->opening->applyThenGuardOpenCoverage(
            $offering,
            function (CourseOffering $locked) use ($data, $request, $requestedClosed): void {
                $attributes = $this->attributesForOfferingUpdate($locked, $data, $request);
                unset($attributes['status'], $attributes['available_seats']);
                if ($requestedClosed) {
                    if ((string) $locked->status === CourseOfferingOpeningService::STATUS_OPEN) {
                        throw CourseOfferingClosureException::workflowRequired();
                    }
                }
                $this->offeringContext->updateOffering($locked, $attributes);
            },
            $requestedOpen,
            $request->user(),
        );

        return $this->successResponse(
            (new CourseOfferingResource($offering->fresh()->load($this->offeringRelations())))->resolve($request)
        );
    }

    public function open(): JsonResponse
    {
        Gate::authorize('viewAny', CourseOffering::class);
        $offerings = app(DataScopeService::class)->scopeOfferings(CourseOffering::query(), request()->user())
            ->with($this->offeringRelations())
            ->withCount('studentCourseRegistrations')
            ->where('status', 'open')
            ->orderBy('course_offering_id', 'desc')
            ->paginate(request()->integer('per_page', 15));

        return $this->successResponse(CourseOfferingResource::collection($offerings)->response(request())->getData(true));
    }

    public function details(int $id): JsonResponse
    {
        $offering = CourseOffering::query()
            ->with($this->offeringRelations())
            ->withCount('studentCourseRegistrations')
            ->findOrFail($id);
        Gate::authorize('view', $offering);

        $payload = (new CourseOfferingResource($offering))->resolve(request());
        $payload['registered_students_count'] = $offering->student_course_registrations_count;

        return $this->successResponse($payload);
    }

    public function students(int $id): JsonResponse
    {
        $offering = CourseOffering::query()->with([
            'studentCourseRegistrations.student',
            'studentCourseRegistrations.registrationStatus',
            'studentCourseRegistrations.resultStatus',
        ])->findOrFail($id);
        Gate::authorize('viewRoster', $offering);

        return $this->successResponse(CourseOfferingStudentResource::collection($offering->studentCourseRegistrations));
    }

    public function capacity(int $id): JsonResponse
    {
        $offering = CourseOffering::query()->findOrFail($id);
        Gate::authorize('view', $offering);
        $registeredCount = $offering->studentCourseRegistrations()->current()->count();
        $capacity = (int) $offering->capacity;
        $availableSeats = (int) $offering->available_seats;

        return $this->successResponse([
            'capacity' => $capacity,
            'available_seats' => $availableSeats,
            'registered_count' => $registeredCount,
            'remaining_seats' => max($availableSeats, 0),
            'occupancy_percentage' => $capacity > 0 ? round(($registeredCount / $capacity) * 100, 2) : 0,
        ]);
    }

    public function bySemester(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CourseOffering::class);
        $validated = $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,academic_year_id'],
            'semester_id' => ['required', 'integer', 'exists:semesters,semester_id'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,department_id'],
            'academic_program_id' => ['sometimes', 'nullable', 'integer', 'exists:academic_programs,academic_program_id'],
            'status' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $offerings = app(DataScopeService::class)->scopeOfferings(CourseOffering::query(), $request->user())
            ->with($this->offeringRelations())
            ->withCount('studentCourseRegistrations')
            ->where('academic_year_id', $validated['academic_year_id'])
            ->where('semester_id', $validated['semester_id'])
            ->when($validated['department_id'] ?? null, fn ($query, $departmentId) => $query->where('department_id', $departmentId))
            ->when($validated['academic_program_id'] ?? null, fn ($query, $programId) => $query->where('academic_program_id', $programId))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('course_offering_id', 'desc')
            ->paginate($request->integer('per_page', 15));

        return $this->successResponse(CourseOfferingResource::collection($offerings)->response($request)->getData(true));
    }

    public function byProgram(Request $request, int $program_id): JsonResponse
    {
        Gate::authorize('viewAny', CourseOffering::class);
        $validated = $request->validate([
            'academic_year_id' => ['sometimes', 'nullable', 'integer', 'exists:academic_years,academic_year_id'],
            'semester_id' => ['sometimes', 'nullable', 'integer', 'exists:semesters,semester_id'],
            'status' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $offerings = app(DataScopeService::class)->scopeOfferings(CourseOffering::query(), $request->user())
            ->with($this->offeringRelations())
            ->withCount('studentCourseRegistrations')
            ->where('academic_program_id', $program_id)
            ->when($validated['academic_year_id'] ?? null, fn ($query, $academicYearId) => $query->where('academic_year_id', $academicYearId))
            ->when($validated['semester_id'] ?? null, fn ($query, $semesterId) => $query->where('semester_id', $semesterId))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('course_offering_id', 'desc')
            ->paginate($request->integer('per_page', 15));

        return $this->successResponse(CourseOfferingResource::collection($offerings)->response($request)->getData(true));
    }

    public function gradeSheet(int $id, GradeService $service, AcademicAuthorizationService $authorization): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('grades.view'), 403);
        $authorization->assertCanAccessOffering(request()->user(), $id);
        $includeInactive = filter_var(request()->query('include_inactive', false), FILTER_VALIDATE_BOOLEAN);

        return $this->successResponse($service->getGradeSheet($id, $includeInactive));
    }

    public function resultsSummary(int $id, GradeService $service, AcademicAuthorizationService $authorization): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('grades.view'), 403);
        $authorization->assertCanAccessOffering(request()->user(), $id);
        return $this->successResponse($service->getResultsSummary($id));
    }

    public function attendanceSessions(int $id, AttendanceService $service, AcademicAuthorizationService $authorization): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('attendance.view'), 403);
        $authorization->assertCanAccessOffering(request()->user(), $id);
        return $this->successResponse($service->getCourseOfferingSessions($id));
    }

    public function storeAttendanceSession(int $id, StoreCourseOfferingAttendanceSessionRequest $request, AttendanceService $service, AcademicAuthorizationService $authorization): JsonResponse
    {
        abort_unless($request->user()->hasPermission('attendance.manage'), 403);
        $authorization->assertCanAccessOffering($request->user(), $id);
        $session = $service->createCourseOfferingSession(
            $id,
            $request->validated(),
            (int) $request->user()->user_id
        );

        return $this->successResponse($session, 'Attendance session created successfully', 201);
    }

    public function deprivedStudents(int $id, AttendanceService $service, AcademicAuthorizationService $authorization): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('attendance.view'), 403);
        $authorization->assertCanAccessOffering(request()->user(), $id);
        return $this->successResponse($service->getDeprivedStudents($id));
    }

    public function applyDeprivation(int $id, AttendanceService $service, AcademicAuthorizationService $authorization): JsonResponse
    {
        $authorization->assertExaminationCommittee(request()->user());
        $authorization->assertCanAccessOffering(request()->user(), $id);
        $result = $service->applyDeprivation($id, request()->user()?->user_id);

        return $this->successResponse($result, 'Deprivation applied successfully');
    }

    private function rejectExternalFacultyMemberAssignment(FormRequest $request, ?CourseOffering $offering = null): void
    {
        if (! $request->exists('faculty_member_id')) {
            return;
        }

        $incoming = $this->normalizeOptionalFacultyMemberId($request->input('faculty_member_id'));

        if ($offering === null) {
            if ($incoming !== null) {
                throw TeachingAssignmentException::facultyMemberAssignmentWorkflowRequired();
            }

            return;
        }

        $current = $offering->faculty_member_id === null ? null : (int) $offering->faculty_member_id;
        if ($incoming !== $current) {
            throw TeachingAssignmentException::facultyMemberAssignmentWorkflowRequired();
        }
    }

    private function normalizeOptionalFacultyMemberId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributesForOfferingUpdate(CourseOffering $offering, array $data, FormRequest $request): array
    {
        unset($data['faculty_member_id']);
        unset($data['status'], $data['available_seats']);
        $identityKeys = ['course_id', 'academic_program_id', 'department_id', 'academic_year_id', 'semester_id'];
        $identityTouched = array_intersect(array_keys($data), $identityKeys) !== [];

        if (! $identityTouched) {
            return $data;
        }

        $courseId = isset($data['course_id']) ? (int) $data['course_id'] : (int) $offering->course_id;
        $programId = array_key_exists('academic_program_id', $data)
            ? (int) $data['academic_program_id']
            : ($offering->academic_program_id === null ? null : (int) $offering->academic_program_id);
        $yearId = isset($data['academic_year_id']) ? (int) $data['academic_year_id'] : (int) $offering->academic_year_id;
        $semesterId = isset($data['semester_id']) ? (int) $data['semester_id'] : (int) $offering->semester_id;
        $departmentId = array_key_exists('department_id', $data)
            ? (int) $data['department_id']
            : ($offering->department_id === null ? null : (int) $offering->department_id);

        if ($programId === null) {
            throw CourseOfferingContextException::programContextIncomplete();
        }

        $this->offeringContext->assertIdentityChangeAllowed($offering, $courseId, $programId, $yearId, $semesterId);

        $context = $this->offeringContext->resolveContext(
            $courseId,
            $programId,
            $yearId,
            $semesterId,
            $departmentId,
            $request->user(),
            true,
            (int) $offering->course_offering_id,
        );

        return array_merge($data, $context->offeringAttributes());
    }

    /**
     * @return list<string>
     */
    private function offeringRelations(): array
    {
        return array_values(array_unique(array_merge([
            'course',
            'academicYear',
            'semester',
            'department.college',
            'academicProgram.department.college',
            'facultyMember',
        ], CourseOfferingInstructorCoverageService::eagerLoadRelations())));
    }
}
