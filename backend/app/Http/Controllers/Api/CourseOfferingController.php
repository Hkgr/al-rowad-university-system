<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\CourseOffering\StoreCourseOfferingRequest;
use App\Http\Requests\CourseOffering\UpdateCourseOfferingRequest;
use App\Http\Requests\Attendance\StoreCourseOfferingAttendanceSessionRequest;
use App\Http\Resources\CourseOfferingResource;
use App\Http\Resources\CourseOfferingStudentResource;
use App\Http\Resources\StudentCourseRegistrationResource;
use App\Models\CourseOffering;
use App\Services\AttendanceService;
use App\Services\GradeService;
use App\Services\AcademicAuthorizationService;
use App\Services\DataScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Http\FormRequest;
use App\Models\AcademicProgram;

class CourseOfferingController extends ApiController
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', CourseOffering::class);
        $offerings = app(DataScopeService::class)->scopeOfferings(CourseOffering::query(), request()->user())
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
        $scope = app(DataScopeService::class);
        abort_unless($scope->canAccessDepartment($request->user(), (int) $data['department_id'])
            && $scope->canAccessProgram($request->user(), (int) $data['academic_program_id']), 403);
        abort_unless(AcademicProgram::query()->whereKey($data['academic_program_id'])->where('department_id', $data['department_id'])->exists(), 422);
        $offering = CourseOffering::query()->create($data);
        return $this->successResponse((new CourseOfferingResource($offering))->resolve($request), 'Operation completed successfully', 201);
    }

    public function update($id): JsonResponse
    {
        $scope = app(DataScopeService::class);
        $offering = CourseOffering::query()->findOrFail($id);
        Gate::authorize('update', $offering);
        /** @var FormRequest $request */
        $request = app($this->updateRequestClass());
        $data = $request->validated();
        $departmentId = (int) ($data['department_id'] ?? $offering->department_id);
        $programId = (int) ($data['academic_program_id'] ?? $offering->academic_program_id);
        abort_unless($scope->canAccessDepartment($request->user(), $departmentId)
            && $scope->canAccessProgram($request->user(), $programId), 403);
        abort_unless(AcademicProgram::query()->whereKey($programId)->where('department_id', $departmentId)->exists(), 422);
        $offering->update($data);
        return $this->successResponse((new CourseOfferingResource($offering->fresh()))->resolve($request));
    }

    public function open(): JsonResponse
    {
        Gate::authorize('viewAny', CourseOffering::class);
        $offerings = app(DataScopeService::class)->scopeOfferings(CourseOffering::query(), request()->user())
            ->with(['course', 'academicYear', 'semester', 'department', 'academicProgram', 'facultyMember'])
            ->withCount('studentCourseRegistrations')
            ->where('status', 'open')
            ->orderBy('course_offering_id', 'desc')
            ->paginate(request()->integer('per_page', 15));

        return $this->successResponse(CourseOfferingResource::collection($offerings)->response(request())->getData(true));
    }

    public function details(int $id): JsonResponse
    {
        $offering = CourseOffering::query()
            ->with(['course', 'academicYear', 'semester', 'department', 'academicProgram', 'facultyMember'])
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
            ->with(['course', 'academicYear', 'semester', 'department', 'academicProgram', 'facultyMember'])
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
            ->with(['course', 'academicYear', 'semester', 'department', 'academicProgram', 'facultyMember'])
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
}
