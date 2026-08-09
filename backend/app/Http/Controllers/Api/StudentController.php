<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Student\StoreStudentRequest;
use App\Http\Requests\Student\UpdateStudentRequest;
use App\Http\Resources\AvailableCourseOfferingResource;
use App\Http\Resources\StudentAcademicInfoResource;
use App\Http\Resources\StudentCourseRegistrationResource;
use App\Http\Resources\StudentDocumentResource;
use App\Http\Resources\StudentProfileResource;
use App\Http\Resources\StudentResource;
use App\Http\Resources\StudentRegistrationSummaryResource;
use App\Http\Resources\StudentTranscriptResource;
use App\Models\Student;
use App\Models\StudentStatus;
use App\Services\AttendanceService;
use App\Services\GradeService;
use App\Services\RegistrationService;
use App\Services\AcademicAuthorizationService;
use App\Services\DataScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class StudentController extends ApiController
{
    public function store(): JsonResponse
    {
        Gate::authorize('create', Student::class);
        $request = app($this->storeRequestClass());
        $data = $request->validated();
        abort_unless(app(DataScopeService::class)->canAccessProgram($request->user(), (int) $data['academic_program_id']), 403);
        $student = Student::query()->create($data);

        return $this->successResponse((new StudentResource($student))->resolve($request), 'Operation completed successfully', 201);
    }

    public function update($id): JsonResponse
    {
        $student = Student::query()->findOrFail($id);
        Gate::authorize('update', $student);
        if (request()->filled('academic_program_id')) {
            abort_unless(app(DataScopeService::class)->canAccessProgram(request()->user(), request()->integer('academic_program_id')), 403);
        }
        $statusCode = request()->input('student_status_code');

        if ($statusCode === null && request()->filled('student_status_id')) {
            $statusCode = StudentStatus::query()
                ->whereKey(request()->integer('student_status_id'))
                ->value('status_code');
        }

        if ($statusCode === 'graduated') {
            return response()->json([
                'success' => false,
                'message' => 'Graduation is temporarily unavailable until institutional eligibility rules are implemented.',
                'error_code' => 'graduation_eligibility_not_implemented',
                'errors' => [],
            ], 409);
        }

        return parent::update($id);
    }

    protected function modelClass(): string
    {
        return Student::class;
    }

    protected function resourceClass(): string
    {
        return StudentResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreStudentRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateStudentRequest::class;
    }

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Student::class);
        $request = request();
        $validated = $request->validate([
            'student_status_id' => ['sometimes', 'integer', 'exists:student_statuses,student_status_id'],
            'student_status_code' => ['sometimes', 'string', 'exists:student_statuses,status_code'],
            'academic_program_id' => ['sometimes', 'integer', 'exists:academic_programs,academic_program_id'],
            'current_academic_level_id' => ['sometimes', 'integer', 'exists:academic_levels,academic_level_id'],
            'q' => ['sometimes', 'string', 'min:1', 'max:150'],
            'search' => ['sometimes', 'string', 'min:1', 'max:150'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = app(DataScopeService::class)->scopeStudents(Student::query(), $request->user());

        if (isset($validated['student_status_id'])) {
            $query->where('student_status_id', $validated['student_status_id']);
        } elseif (isset($validated['student_status_code'])) {
            $query->whereHas('studentStatus', fn ($status) => $status->where('status_code', $validated['student_status_code']));
        }

        if (isset($validated['academic_program_id'])) {
            $query->where('academic_program_id', $validated['academic_program_id']);
        }

        if (isset($validated['current_academic_level_id'])) {
            $query->where('current_academic_level_id', $validated['current_academic_level_id']);
        }

        $searchTerm = $validated['q'] ?? $validated['search'] ?? null;

        if ($searchTerm !== null) {
            $query->where(function ($builder) use ($searchTerm): void {
                $builder->where('student_number', 'like', "%{$searchTerm}%")
                    ->orWhere('first_name', 'like', "%{$searchTerm}%")
                    ->orWhere('last_name', 'like', "%{$searchTerm}%")
                    ->orWhere('email', 'like', "%{$searchTerm}%")
                    ->orWhere('phone_number', 'like', "%{$searchTerm}%");
            });
        }

        $students = $query
            ->orderBy('student_number')
            ->paginate($request->integer('per_page', 15));

        $payload = StudentResource::collection($students)
            ->response($request)
            ->getData(true);

        return $this->successResponse($payload);
    }

    public function destroy($id): JsonResponse
    {
        $student = Student::query()->findOrFail($id);
        Gate::authorize('delete', $student);
        $student->delete();

        return $this->successResponse(null, 'Student archived successfully.');
    }

    public function deleted(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Student::class);
        $students = app(DataScopeService::class)->scopeStudents(Student::onlyTrashed(), $request->user())
            ->orderBy('student_number')
            ->paginate($request->integer('per_page', 15));

        return $this->successResponse(
            StudentResource::collection($students)->resolve($request),
            'Deleted students retrieved successfully.'
        );
    }

    public function restore(int $id): JsonResponse
    {
        $student = Student::withTrashed()->findOrFail($id);
        Gate::authorize('restore', $student);

        if (! $student->trashed()) {
            return $this->errorResponse('Student is not archived.', [], 400);
        }

        $student->restore();

        return $this->successResponse(new \stdClass, 'Student restored successfully.');
    }

    public function forceDestroy(int $id): JsonResponse
    {
        $student = Student::withTrashed()->findOrFail($id);
        Gate::authorize('forceDelete', $student);
        $relatedRecords = $this->getBlockingRelatedRecords($student);

        if ($relatedRecords !== []) {
            return $this->errorResponse(
                'Student cannot be permanently deleted because academic records exist.',
                ['related_records' => $relatedRecords],
                409
            );
        }

        $student->forceDelete();

        return $this->successResponse(null, 'Student permanently deleted successfully.');
    }

    /**
     * @return list<string>
     */
    private function getBlockingRelatedRecords(Student $student): array
    {
        $related = [];

        if ($student->studentCourseRegistrations()->exists()) {
            $related[] = 'student_course_registrations';
        }

        if ($student->studentAttendances()->exists()) {
            $related[] = 'student_attendance';
        }

        if ($student->studentDocuments()->exists()) {
            $related[] = 'student_documents';
        }

        if ($student->studentAcademicTerms()->exists()) {
            $related[] = 'student_academic_terms';
        }

        $registrationIds = $student->studentCourseRegistrations()->pluck('student_course_registration_id');

        if ($registrationIds->isNotEmpty()) {
            if (DB::table('student_course_results')->whereIn('student_course_registration_id', $registrationIds)->exists()) {
                $related[] = 'student_course_results';
            }

            if (DB::table('student_grade_components')->whereIn('student_course_registration_id', $registrationIds)->exists()) {
                $related[] = 'student_grade_components';
            }
        }

        return array_values(array_unique($related));
    }

    public function search(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Student::class);
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:150'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $validated['q'];

        $students = app(DataScopeService::class)->scopeStudents(Student::query(), $request->user())
            ->where(function ($builder) use ($query): void {
                $builder->where('student_number', 'like', "%{$query}%")
                    ->orWhere('first_name', 'like', "%{$query}%")
                    ->orWhere('last_name', 'like', "%{$query}%")
                    ->orWhere('email', 'like', "%{$query}%")
                    ->orWhere('phone_number', 'like', "%{$query}%");
            })
            ->orderBy('student_number')
            ->paginate($request->integer('per_page', 15));

        return $this->successResponse(
            StudentResource::collection($students)->response($request)->getData(true)
        );
    }

    public function profile(Student $student): JsonResponse
    {
        Gate::authorize('view', $student);
        $student->load([
            'currentAcademicLevel',
            'studentStatus',
            'academicProgram.department.college',
        ]);

        return $this->successResponse(
            (new StudentProfileResource($student))->resolve(request())
        );
    }

    public function academicInfo(Student $student): JsonResponse
    {
        Gate::authorize('view', $student);
        $student->load(['academicProgram.department.college', 'currentAcademicLevel', 'studentStatus']);

        return $this->successResponse(
            (new StudentAcademicInfoResource($student))->resolve(request())
        );
    }

    public function documents(Student $student): JsonResponse
    {
        Gate::authorize('view', $student);
        $documents = $student->studentDocuments()
            ->with('documentType')
            ->latest('student_document_id')
            ->paginate(request()->integer('per_page', 15));

        return $this->successResponse(
            StudentDocumentResource::collection($documents)->response(request())->getData(true)
        );
    }

    public function registrations(Student $student): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('registration.view'), 403);
        $registrations = app(DataScopeService::class)->scopeRegistrations($student->studentCourseRegistrations(), request()->user())
            ->with([
                'courseOffering.course',
                'courseOffering.academicYear',
                'courseOffering.semester',
                'registrationStatus',
                'resultStatus',
                'studentCourseResult.resultStatus',
            ])
            ->latest('student_course_registration_id')
            ->paginate(request()->integer('per_page', 15));

        return $this->successResponse(
            StudentCourseRegistrationResource::collection($registrations)->response(request())->getData(true)
        );
    }

    public function transcript(Student $student, GradeService $gradeService): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('grades.view'), 403);
        app(AcademicAuthorizationService::class)->assertCanAccessStudent(request()->user(), $student);
        return $this->successResponse($gradeService->getTranscript($student));
    }

    public function gpa(Student $student, Request $request, GradeService $gradeService): JsonResponse
    {
        abort_unless($request->user()->hasPermission('grades.view'), 403);
        app(AcademicAuthorizationService::class)->assertCanAccessStudent($request->user(), $student);
        $validated = $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,academic_year_id'],
            'semester_id' => ['required', 'integer', 'exists:semesters,semester_id'],
        ]);

        return $this->successResponse(
            $gradeService->calculateGpa(
                $student,
                (int) $validated['academic_year_id'],
                (int) $validated['semester_id']
            )
        );
    }

    public function cgpa(Student $student, GradeService $gradeService): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('grades.view'), 403);
        app(AcademicAuthorizationService::class)->assertCanAccessStudent(request()->user(), $student);
        return $this->successResponse($gradeService->calculateCgpa($student));
    }

    public function attendance(Student $student, Request $request, AttendanceService $service): JsonResponse
    {
        abort_unless($request->user()->hasPermission('attendance.view'), 403);
        app(AcademicAuthorizationService::class)->assertCanAccessStudent($request->user(), $student);
        $validated = $request->validate([
            'academic_year_id' => ['sometimes', 'integer', 'exists:academic_years,academic_year_id'],
            'semester_id' => ['sometimes', 'integer', 'exists:semesters,semester_id'],
            'course_offering_id' => ['sometimes', 'integer', 'exists:course_offerings,course_offering_id'],
        ]);

        return $this->successResponse(
            $service->getStudentAttendance(
                $student,
                $validated['academic_year_id'] ?? null,
                $validated['semester_id'] ?? null,
                $validated['course_offering_id'] ?? null,
                $request->user()
            )
        );
    }

    public function absencePercentage(Student $student, Request $request, AttendanceService $service): JsonResponse
    {
        abort_unless($request->user()->hasPermission('attendance.view'), 403);
        app(AcademicAuthorizationService::class)->assertCanAccessStudent($request->user(), $student);
        $validated = $request->validate([
            'course_offering_id' => ['required', 'integer', 'exists:course_offerings,course_offering_id'],
        ]);
        abort_unless(app(DataScopeService::class)->scopeRegistrations(
            $student->studentCourseRegistrations(), $request->user()
        )->where('course_offering_id', $validated['course_offering_id'])->exists(), 403);

        return $this->successResponse(
            $service->getStudentAbsencePercentage($student, (int) $validated['course_offering_id'])
        );
    }

    public function availableCourses(Student $student, Request $request, RegistrationService $service): JsonResponse
    {
        Gate::authorize('view', $student);
        $validated = $request->validate([
            'academic_year_id' => ['sometimes', 'integer', 'exists:academic_years,academic_year_id'],
            'semester_id' => ['sometimes', 'integer', 'exists:semesters,semester_id'],
        ]);

        $offerings = $service->getAvailableCourses(
            $student,
            $validated['academic_year_id'] ?? null,
            $validated['semester_id'] ?? null
        );

        return $this->successResponse(
            AvailableCourseOfferingResource::collection($offerings)->resolve($request)
        );
    }

    public function registeredHours(Student $student, Request $request, RegistrationService $service): JsonResponse
    {
        Gate::authorize('view', $student);
        $validated = $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,academic_year_id'],
            'semester_id' => ['required', 'integer', 'exists:semesters,semester_id'],
        ]);

        return $this->successResponse(
            $service->getRegisteredHours(
                $student,
                (int) $validated['academic_year_id'],
                (int) $validated['semester_id']
            )
        );
    }

    public function registrationSummary(Student $student, Request $request, RegistrationService $service): JsonResponse
    {
        Gate::authorize('view', $student);
        $validated = $request->validate([
            'academic_year_id' => ['sometimes', 'integer', 'exists:academic_years,academic_year_id'],
            'semester_id' => ['sometimes', 'integer', 'exists:semesters,semester_id'],
        ]);

        $summary = $service->getRegistrationSummary(
            $student,
            $validated['academic_year_id'] ?? null,
            $validated['semester_id'] ?? null
        );

        return $this->successResponse(
            (new StudentRegistrationSummaryResource($summary))->resolve($request)
        );
    }
}
