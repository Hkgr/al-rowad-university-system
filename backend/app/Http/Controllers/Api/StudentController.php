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
use App\Services\AttendanceService;
use App\Services\GradeService;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentController extends ApiController
{
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

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:150'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $validated['q'];

        $students = Student::query()
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
        $student->load(['academicProgram.department.college', 'currentAcademicLevel', 'studentStatus']);

        return $this->successResponse(
            (new StudentAcademicInfoResource($student))->resolve(request())
        );
    }

    public function documents(Student $student): JsonResponse
    {
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
        $registrations = $student->studentCourseRegistrations()
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
        return $this->successResponse($gradeService->getTranscript($student));
    }

    public function gpa(Student $student, Request $request, GradeService $gradeService): JsonResponse
    {
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
        return $this->successResponse($gradeService->calculateCgpa($student));
    }

    public function attendance(Student $student, Request $request, AttendanceService $service): JsonResponse
    {
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
                $validated['course_offering_id'] ?? null
            )
        );
    }

    public function absencePercentage(Student $student, Request $request, AttendanceService $service): JsonResponse
    {
        $validated = $request->validate([
            'course_offering_id' => ['required', 'integer', 'exists:course_offerings,course_offering_id'],
        ]);

        return $this->successResponse(
            $service->getStudentAbsencePercentage($student, (int) $validated['course_offering_id'])
        );
    }

    public function availableCourses(Student $student, Request $request, RegistrationService $service): JsonResponse
    {
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
