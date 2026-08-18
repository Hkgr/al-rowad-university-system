<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeanCourseOfferingSessionResource;
use App\Http\Resources\DeanCourseOfferingStudentResource;
use App\Http\Resources\DeanCourseOfferingSummaryResource;
use App\Models\AcademicProgram;
use App\Models\AcademicYear;
use App\Models\AttendanceSession;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Semester;
use App\Models\StudentCourseRegistration;
use App\Models\StudentCourseResult;
use App\Models\User;
use App\Services\CourseOfferingInstructorCoverageService;
use App\Services\DataScopeService;
use App\Services\GradeService;
use App\Services\TeachingAssignmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class DeanCourseOfferingController extends Controller
{
    public function __construct(
        private DataScopeService $dataScope,
        private TeachingAssignmentService $teachingAssignments,
        private GradeService $gradeService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->assertCanViewCourses($request);

        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'min:1', 'max:150'],
            'academic_year_id' => ['sometimes', 'integer', 'min:1', 'exists:academic_years,academic_year_id'],
            'semester_id' => ['sometimes', 'integer', 'min:1', 'exists:semesters,semester_id'],
            'department_id' => ['sometimes', 'integer', 'min:1', 'exists:departments,department_id'],
            'academic_program_id' => ['sometimes', 'integer', 'min:1', 'exists:academic_programs,academic_program_id'],
            'status' => ['sometimes', 'string', 'min:1', 'max:50'],
            'teacher_assignment' => ['sometimes', Rule::in(['all', 'fully_assigned', 'partially_assigned', 'unassigned'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        $canViewGrades = $user->hasPermission('grades.view');
        $scopedIds = $this->scopedOfferingIdsQuery($user);

        $query = $this->offeringListQuery($scopedIds);
        $this->applyListFilters($query, $validated);

        $summary = $this->buildListSummary(clone $query);

        $query->with($this->offeringDisplayRelations())
            ->withCount($this->offeringMetricCounts());

        if ($canViewGrades) {
            $this->addGradeAggregates($query);
        }

        $offerings = $query
            ->orderByDesc('academic_years.start_date')
            ->orderByDesc('semesters.semester_order')
            ->orderBy('courses.course_code')
            ->orderBy('courses.course_name')
            ->paginate((int) ($validated['per_page'] ?? 15));

        $payload = DeanCourseOfferingSummaryResource::collection($offerings)
            ->response($request)
            ->getData(true);
        $payload['summary'] = $summary;
        $payload['filter_options'] = $this->filterOptions($scopedIds);

        return $this->successResponse($payload);
    }

    public function show(Request $request, CourseOffering $courseOffering): JsonResponse
    {
        $this->assertCanViewCourses($request);

        $query = CourseOffering::query()
            ->whereIn('course_offerings.course_offering_id', $this->scopedOfferingIdsQuery($request->user()))
            ->whereKey($courseOffering->course_offering_id)
            ->with($this->offeringDisplayRelations())
            ->withCount($this->offeringMetricCounts());

        if ($request->user()->hasPermission('grades.view')) {
            $this->addGradeAggregates($query);
        }

        $offering = $query->firstOrFail();
        $payload = (new DeanCourseOfferingSummaryResource($offering))->resolve($request);

        if ($request->user()->hasPermission('grades.view')) {
            $payload['results_summary'] = $this->gradeService->getResultsSummary(
                (int) $offering->course_offering_id
            );
        }

        return $this->successResponse($payload);
    }

    public function students(Request $request, CourseOffering $courseOffering): JsonResponse
    {
        $this->assertCanViewCourses($request);
        $this->assertCanViewRoster($request);
        $offering = $this->resolveAccessibleOffering($request->user(), $courseOffering);

        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'min:1', 'max:150'],
            'registration_status' => ['sometimes', 'string', 'min:1', 'max:50'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $canViewGrades = $request->user()->hasPermission('grades.view');
        $statusFilter = $validated['registration_status'] ?? StudentCourseRegistration::CURRENT_STATUS;

        $query = StudentCourseRegistration::query()
            ->where('course_offering_id', $offering->course_offering_id)
            ->with([
                'student',
                'registrationStatus',
            ]);

        if ($canViewGrades) {
            $query->with('studentCourseResult.resultStatus');
        }

        if ($statusFilter !== 'all') {
            $query->whereHas(
                'registrationStatus',
                fn (Builder $status) => $status->where('status_code', $statusFilter)
            );
        }

        if (isset($validated['search'])) {
            $pattern = $this->likeContains($validated['search']);
            $query->whereHas('student', function (Builder $student) use ($pattern): void {
                $student->where(function (Builder $fields) use ($pattern): void {
                    $fields
                        ->where('student_number', 'like', $pattern)
                        ->orWhere('first_name', 'like', $pattern)
                        ->orWhere('last_name', 'like', $pattern)
                        ->orWhereRaw(
                            "CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) LIKE ?",
                            [$pattern]
                        );
                });
            });
        }

        $registrations = $query
            ->orderByDesc('registration_date')
            ->orderBy('student_course_registration_id')
            ->paginate((int) ($validated['per_page'] ?? 15));

        $payload = DeanCourseOfferingStudentResource::collection($registrations)
            ->response($request)
            ->getData(true);
        $payload['includes_grades'] = $canViewGrades;

        return $this->successResponse($payload);
    }

    public function sessions(Request $request, CourseOffering $courseOffering): JsonResponse
    {
        $this->assertCanViewCourses($request);
        $this->assertCanViewAttendance($request);
        $offering = $this->resolveAccessibleOffering($request->user(), $courseOffering);

        $validated = $request->validate([
            'session_type' => ['sometimes', Rule::in(['theoretical', 'practical', 'lecture'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = AttendanceSession::query()
            ->where('attendance_sessions.course_offering_id', $offering->course_offering_id)
            ->with('facultyMember.employee')
            ->withCount('studentAttendances as recorded_count');

        if (isset($validated['session_type'])) {
            $query->where('attendance_sessions.session_type', $validated['session_type']);
        }

        $sessions = $query
            ->orderByDesc('attendance_sessions.session_date')
            ->orderByDesc('attendance_sessions.start_time')
            ->orderByDesc('attendance_sessions.attendance_session_id')
            ->paginate((int) ($validated['per_page'] ?? 15));

        return $this->successResponse(
            DeanCourseOfferingSessionResource::collection($sessions)
                ->response($request)
                ->getData(true)
        );
    }

    private function offeringListQuery(Builder $scopedIds): Builder
    {
        return CourseOffering::query()
            ->whereIn('course_offerings.course_offering_id', $scopedIds)
            ->join('courses', 'courses.course_id', '=', 'course_offerings.course_id')
            ->leftJoin(
                'academic_years',
                'academic_years.academic_year_id',
                '=',
                'course_offerings.academic_year_id'
            )
            ->leftJoin(
                'semesters',
                'semesters.semester_id',
                '=',
                'course_offerings.semester_id'
            )
            ->leftJoin(
                'departments',
                'departments.department_id',
                '=',
                'course_offerings.department_id'
            )
            ->leftJoin(
                'academic_programs',
                'academic_programs.academic_program_id',
                '=',
                'course_offerings.academic_program_id'
            )
            ->select('course_offerings.*');
    }

    private function applyListFilters(Builder $query, array $validated): void
    {
        if (isset($validated['search'])) {
            $pattern = $this->likeContains($validated['search']);
            $query->where(function (Builder $search) use ($pattern): void {
                $search
                    ->where('courses.course_code', 'like', $pattern)
                    ->orWhere('courses.course_name', 'like', $pattern)
                    ->orWhere('departments.department_name', 'like', $pattern)
                    ->orWhere('academic_programs.program_name', 'like', $pattern);
            });
        }

        if (isset($validated['academic_year_id'])) {
            $query->where('course_offerings.academic_year_id', (int) $validated['academic_year_id']);
        }

        if (isset($validated['semester_id'])) {
            $query->where('course_offerings.semester_id', (int) $validated['semester_id']);
        }

        if (isset($validated['department_id'])) {
            $departmentId = (int) $validated['department_id'];
            $query->where(function (Builder $department) use ($departmentId): void {
                $department
                    ->where('course_offerings.department_id', $departmentId)
                    ->orWhereHas(
                        'academicProgram',
                        fn (Builder $program) => $program->where('department_id', $departmentId)
                    );
            });
        }

        if (isset($validated['academic_program_id'])) {
            $query->where('course_offerings.academic_program_id', (int) $validated['academic_program_id']);
        }

        if (isset($validated['status'])) {
            $query->where('course_offerings.status', $validated['status']);
        }

        $this->applyTeacherAssignmentFilter($query, $validated['teacher_assignment'] ?? 'all');
    }

    private function applyTeacherAssignmentFilter(Builder $query, string $filter): void
    {
        if ($filter === 'all') {
            return;
        }

        match ($filter) {
            'fully_assigned' => $query
                ->where(fn (Builder $theoretical) => $this->theoreticalSlotSatisfied($theoretical))
                ->where(fn (Builder $practical) => $this->practicalSlotSatisfied($practical)),
            'unassigned' => $query
                ->where(fn (Builder $required) => $this->hasAnyRequiredComponent($required))
                ->where(fn (Builder $theoretical) => $this->theoreticalSlotUnassignedOrAbsent($theoretical))
                ->where(fn (Builder $practical) => $this->practicalSlotUnassignedOrAbsent($practical)),
            'partially_assigned' => $query
                ->where(fn (Builder $assigned) => $this->hasAnyAssignedRequiredComponent($assigned))
                ->where(fn (Builder $missing) => $this->hasAnyMissingRequiredComponent($missing)),
            default => null,
        };
    }

    private function theoreticalSlotSatisfied(Builder $query): void
    {
        $query
            ->where(fn (Builder $absent) => $this->lacksTheoreticalComponent($absent))
            ->orWhere(function (Builder $assigned): void {
                $this->requiresTheoreticalComponent($assigned);
                $this->hasActiveRole($assigned, 'theoretical');
            });
    }

    private function practicalSlotSatisfied(Builder $query): void
    {
        $query
            ->where(fn (Builder $absent) => $this->lacksPracticalComponent($absent))
            ->orWhere(function (Builder $assigned): void {
                $this->requiresPracticalComponent($assigned);
                $this->hasActiveRole($assigned, 'practical');
            });
    }

    private function theoreticalSlotUnassignedOrAbsent(Builder $query): void
    {
        $query
            ->where(fn (Builder $absent) => $this->lacksTheoreticalComponent($absent))
            ->orWhere(function (Builder $missing): void {
                $this->requiresTheoreticalComponent($missing);
                $this->missingActiveRole($missing, 'theoretical');
            });
    }

    private function practicalSlotUnassignedOrAbsent(Builder $query): void
    {
        $query
            ->where(fn (Builder $absent) => $this->lacksPracticalComponent($absent))
            ->orWhere(function (Builder $missing): void {
                $this->requiresPracticalComponent($missing);
                $this->missingActiveRole($missing, 'practical');
            });
    }

    private function hasAnyRequiredComponent(Builder $query): void
    {
        $query
            ->where(fn (Builder $theoretical) => $this->requiresTheoreticalComponent($theoretical))
            ->orWhere(fn (Builder $practical) => $this->requiresPracticalComponent($practical));
    }

    private function hasAnyAssignedRequiredComponent(Builder $query): void
    {
        $query
            ->where(function (Builder $theoretical): void {
                $this->requiresTheoreticalComponent($theoretical);
                $this->hasActiveRole($theoretical, 'theoretical');
            })
            ->orWhere(function (Builder $practical): void {
                $this->requiresPracticalComponent($practical);
                $this->hasActiveRole($practical, 'practical');
            });
    }

    private function hasAnyMissingRequiredComponent(Builder $query): void
    {
        $query
            ->where(function (Builder $theoretical): void {
                $this->requiresTheoreticalComponent($theoretical);
                $this->missingActiveRole($theoretical, 'theoretical');
            })
            ->orWhere(function (Builder $practical): void {
                $this->requiresPracticalComponent($practical);
                $this->missingActiveRole($practical, 'practical');
            });
    }

    private function requiresTheoreticalComponent(Builder $query): void
    {
        $query->whereHas('course', fn (Builder $course) => $course->where('theoretical_hours', '>', 0));
    }

    private function requiresPracticalComponent(Builder $query): void
    {
        $query->whereHas('course', fn (Builder $course) => $course->where('practical_hours', '>', 0));
    }

    private function lacksTheoreticalComponent(Builder $query): void
    {
        $query->whereHas(
            'course',
            fn (Builder $course) => $course->where(function (Builder $hours): void {
                $hours->whereNull('theoretical_hours')->orWhere('theoretical_hours', '<=', 0);
            })
        );
    }

    private function lacksPracticalComponent(Builder $query): void
    {
        $query->whereHas(
            'course',
            fn (Builder $course) => $course->where(function (Builder $hours): void {
                $hours->whereNull('practical_hours')->orWhere('practical_hours', '<=', 0);
            })
        );
    }

    private function hasActiveRole(Builder $query, string $role): void
    {
        $query->whereHas(
            'offeringInstructors',
            fn (Builder $slot) => $slot->where('is_active', true)->where('instructor_role', $role)
        );
    }

    private function missingActiveRole(Builder $query, string $role): void
    {
        $query->whereDoesntHave(
            'offeringInstructors',
            fn (Builder $slot) => $slot->where('is_active', true)->where('instructor_role', $role)
        );
    }

    private function offeringMetricCounts(): array
    {
        return [
            'studentCourseRegistrations as registered_students_count' => fn (Builder $registrations) => $registrations->current(),
            'attendanceSessions as attendance_sessions_count',
            'attendanceSessions as theoretical_sessions_count' => fn (Builder $sessions) => $sessions
                ->whereIn('session_type', ['theoretical', 'lecture']),
            'attendanceSessions as practical_sessions_count' => fn (Builder $sessions) => $sessions
                ->where('session_type', 'practical'),
        ];
    }

    private function addGradeAggregates(Builder $query): void
    {
        $query->addSelect([
            'average_final_mark' => $this->finalMarkAggregateQuery('AVG(student_course_results.final_mark)'),
            'graded_students_count' => $this->finalMarkAggregateQuery('COUNT(student_course_results.student_course_result_id)'),
        ]);
    }

    private function finalMarkAggregateQuery(string $aggregate): Builder
    {
        return StudentCourseResult::query()
            ->selectRaw($aggregate)
            ->join(
                'student_course_registrations as dean_offering_scr',
                'dean_offering_scr.student_course_registration_id',
                '=',
                'student_course_results.student_course_registration_id'
            )
            ->join(
                'registration_statuses as dean_offering_rs',
                'dean_offering_rs.registration_status_id',
                '=',
                'dean_offering_scr.registration_status_id'
            )
            ->whereColumn('dean_offering_scr.course_offering_id', 'course_offerings.course_offering_id')
            ->whereIn('dean_offering_rs.status_code', StudentCourseRegistration::HISTORICAL_ATTEMPT_STATUSES)
            ->whereNotNull('student_course_results.final_mark');
    }

    private function buildListSummary(Builder $filtered): array
    {
        $offeringIds = (clone $filtered)->select('course_offerings.course_offering_id');

        $incomplete = clone $filtered;
        $incomplete->where(fn (Builder $missing) => $this->hasAnyMissingRequiredComponent($missing));

        return [
            'total_offerings' => (clone $filtered)->count('course_offerings.course_offering_id'),
            'open_offerings' => (clone $filtered)
                ->where('course_offerings.status', 'open')
                ->count('course_offerings.course_offering_id'),
            'registered_students_count' => StudentCourseRegistration::query()
                ->whereIn('course_offering_id', $offeringIds)
                ->current()
                ->count(),
            'incomplete_assignment_count' => $incomplete->count('course_offerings.course_offering_id'),
        ];
    }

    private function filterOptions(Builder $scopedIds): array
    {
        $base = CourseOffering::query()->whereIn('course_offering_id', $scopedIds);

        $yearIds = (clone $base)->whereNotNull('academic_year_id')->distinct()->pluck('academic_year_id');
        $semesterIds = (clone $base)->whereNotNull('semester_id')->distinct()->pluck('semester_id');
        $directDepartmentIds = (clone $base)->whereNotNull('department_id')->distinct()->pluck('department_id');
        $programIds = (clone $base)->whereNotNull('academic_program_id')->distinct()->pluck('academic_program_id');
        $programDepartmentIds = AcademicProgram::query()
            ->whereIn('academic_program_id', $programIds)
            ->whereNotNull('department_id')
            ->pluck('department_id');
        $departmentIds = $directDepartmentIds->merge($programDepartmentIds)->unique()->values();
        $statuses = (clone $base)
            ->whereNotNull('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status')
            ->values()
            ->all();

        return [
            'academic_years' => AcademicYear::query()
                ->whereIn('academic_year_id', $yearIds)
                ->orderByDesc('start_date')
                ->get(['academic_year_id', 'year_name'])
                ->all(),
            'semesters' => Semester::query()
                ->whereIn('semester_id', $semesterIds)
                ->orderBy('semester_order')
                ->get(['semester_id', 'semester_name', 'semester_order'])
                ->all(),
            'departments' => Department::query()
                ->whereIn('department_id', $departmentIds)
                ->orderBy('department_name')
                ->get(['department_id', 'department_name'])
                ->all(),
            'academic_programs' => AcademicProgram::query()
                ->whereIn('academic_program_id', $programIds)
                ->orderBy('program_name')
                ->get(['academic_program_id', 'program_name'])
                ->all(),
            'statuses' => $statuses,
        ];
    }

    private function offeringDisplayRelations(): array
    {
        $relations = array_merge(
            CourseOfferingInstructorCoverageService::eagerLoadRelations(),
            [
                'academicYear',
                'semester',
                'department',
                'academicProgram',
                'offeringInstructors' => fn ($instructors) => $instructors
                    ->where('is_active', true)
                    ->with('facultyMember.employee.employeeStatus'),
            ]
        );

        if (Schema::hasTable('teaching_assignment_requests')) {
            $relations['teachingAssignmentRequests'] = fn ($requests) => $requests
                ->where('current_slot', 1)
                ->with(['reviews', 'facultyMember.employee']);
        }

        return $relations;
    }

    private function scopedOfferingIdsQuery(User $user): Builder
    {
        $query = CourseOffering::query()->select('course_offerings.course_offering_id');
        $this->dataScope->scopeOfferings($query, $user);

        return $query->whereIn(
            'course_offerings.course_offering_id',
            $this->teachingAssignments->offeringsInAccessibleCollegesQuery(
                $this->teachingAssignments->accessibleCollegeIdList($user)
            )
        );
    }

    private function resolveAccessibleOffering(User $user, CourseOffering $courseOffering): CourseOffering
    {
        return CourseOffering::query()
            ->whereIn('course_offerings.course_offering_id', $this->scopedOfferingIdsQuery($user))
            ->whereKey($courseOffering->course_offering_id)
            ->firstOrFail();
    }

    private function likeContains(string $term): string
    {
        return '%'.addcslashes($term, "%_\\").'%';
    }

    private function assertCanViewCourses(Request $request): void
    {
        $user = $request->user();
        if ($user === null || ! $user->hasPermission('courses.view')) {
            throw new AccessDeniedHttpException('ليس لديك صلاحية لعرض مواد الكلية.');
        }
    }

    private function assertCanViewRoster(Request $request): void
    {
        $user = $request->user();
        if ($user === null
            || ! $user->hasPermission('students.view')
            || ! $user->hasPermission('registration.view')) {
            throw new AccessDeniedHttpException('ليس لديك صلاحية لعرض الطلاب المسجلين.');
        }
    }

    private function assertCanViewAttendance(Request $request): void
    {
        $user = $request->user();
        if ($user === null || ! $user->hasPermission('attendance.view')) {
            throw new AccessDeniedHttpException('ليس لديك صلاحية لعرض جلسات المادة.');
        }
    }

    protected function successResponse(mixed $data = [], string $message = 'Operation completed successfully', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }
}
