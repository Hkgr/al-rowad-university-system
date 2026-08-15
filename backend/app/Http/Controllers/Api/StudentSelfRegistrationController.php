<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\RegistrationException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AcademicYearResource;
use App\Http\Resources\AvailableCourseOfferingResource;
use App\Http\Resources\SemesterResource;
use App\Http\Resources\StudentCourseRegistrationResource;
use App\Http\Resources\StudentRegistrationResultResource;
use App\Http\Resources\StudentRegistrationSummaryResource;
use App\Models\AcademicYear;
use App\Models\CourseOffering;
use App\Models\Student;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class StudentSelfRegistrationController extends Controller
{
    public function __construct(private RegistrationService $registration)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $student = $this->authenticatedStudent($request);
        $validated = $request->validate([
            'semester_id' => ['sometimes', 'integer', 'min:1', 'exists:semesters,semester_id'],
        ]);

        $year = $this->uniqueCurrentAcademicYear();

        $openSemesters = $year === null
            ? collect()
            : $this->registration->selfRegistrationOpenSemesters($student, (int) $year->academic_year_id);

        $requestedSemesterId = isset($validated['semester_id']) ? (int) $validated['semester_id'] : null;
        $semester = $requestedSemesterId !== null
            ? $openSemesters->firstWhere('semester_id', $requestedSemesterId)
            : null;

        if ($semester === null && $openSemesters->count() === 1) {
            $semester = $openSemesters->first();
        }

        $registrationOpen = $openSemesters->isNotEmpty();
        $available = [];
        $summary = null;

        if ($year !== null && $semester !== null) {
            $available = $this->registration->getSelfRegistrationOfferings(
                $student,
                (int) $year->academic_year_id,
                (int) $semester->semester_id
            );
            $summary = $this->registration->getRegistrationSummary(
                $student,
                (int) $year->academic_year_id,
                (int) $semester->semester_id
            );
        } elseif ($year !== null && ! $registrationOpen) {
            $summary = $this->registration->getRegistrationSummary(
                $student,
                (int) $year->academic_year_id,
                $requestedSemesterId
            );
        }

        return $this->successResponse([
            'registration_open' => $registrationOpen,
            'academic_year' => $year === null ? null : (new AcademicYearResource($year))->resolve($request),
            'semester' => $semester === null ? null : (new SemesterResource($semester))->resolve($request),
            'semesters' => SemesterResource::collection($openSemesters)->resolve($request),
            'available_courses' => AvailableCourseOfferingResource::collection($available)->resolve($request),
            'summary' => $summary === null
                ? null
                : (new StudentRegistrationSummaryResource($summary))->resolve($request),
        ]);
    }

    public function register(Request $request, CourseOffering $courseOffering): JsonResponse
    {
        $student = $this->authenticatedStudent($request);
        if ($this->uniqueCurrentAcademicYear() === null) {
            throw new RegistrationException('The selected course offering is not open for the current academic term.', [
                'course_offering_id' => ['The selected course offering is not open for the current academic term.'],
            ]);
        }
        $this->registration->assertSelfRegistrationAllowed($student, $courseOffering);

        $result = $this->registration->registerStudent(
            [
                'student_id' => $student->student_id,
                'course_offering_id' => $courseOffering->course_offering_id,
            ],
            $request->user()?->user_id
        );

        return $this->successResponse(
            (new StudentRegistrationResultResource($result))->resolve($request),
            'تم تسجيل المادة بنجاح.',
            201
        );
    }

    public function drop(Request $request, int $id): JsonResponse
    {
        $student = $this->authenticatedStudent($request);
        $registration = $this->registration->findOrFail($id);
        $this->registration->assertSelfDropAllowed($student, $registration);
        $updated = $this->registration->dropRegistration($registration);

        return $this->successResponse(
            (new StudentCourseRegistrationResource($updated))->resolve($request),
            'تم حذف تسجيل المادة بنجاح.'
        );
    }

    private function uniqueCurrentAcademicYear(): ?AcademicYear
    {
        $currentYears = AcademicYear::query()->where('is_current', true)->get();

        return $currentYears->count() === 1 ? $currentYears->first() : null;
    }

    private function authenticatedStudent(Request $request): Student
    {
        $user = $request->user();
        if ($user === null || $user->student_id === null) {
            throw new AccessDeniedHttpException('يجب أن يكون للحساب سجل طالب لتسجيل المواد.');
        }

        $student = Student::query()->find($user->student_id);
        if ($student === null) {
            throw new AccessDeniedHttpException('يجب أن يكون للحساب سجل طالب لتسجيل المواد.');
        }

        return $student;
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
