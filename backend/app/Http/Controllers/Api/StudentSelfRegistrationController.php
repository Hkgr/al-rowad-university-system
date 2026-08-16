<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AcademicYearResource;
use App\Http\Resources\AvailableCourseOfferingResource;
use App\Http\Resources\SemesterResource;
use App\Http\Resources\StudentRegistrationSummaryResource;
use App\Models\CourseOffering;
use App\Models\Student;
use App\Models\StudentRegistrationRequestItem;
use App\Services\RegistrationRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class StudentSelfRegistrationController extends Controller
{
    public function __construct(private RegistrationRequestService $requests)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $student = $this->authenticatedStudent($request);
        $validated = $request->validate([
            'semester_id' => ['sometimes', 'integer', 'min:1', 'exists:semesters,semester_id'],
        ]);

        $workspace = $this->requests->studentWorkspace(
            $student,
            isset($validated['semester_id']) ? (int) $validated['semester_id'] : null
        );

        return $this->successResponse([
            'registration_open' => $workspace['registration_open'],
            'academic_year' => $workspace['academic_year'] === null
                ? null
                : (new AcademicYearResource($workspace['academic_year']))->resolve($request),
            'semester' => $workspace['semester'] === null
                ? null
                : (new SemesterResource($workspace['semester']))->resolve($request),
            'semesters' => SemesterResource::collection($workspace['semesters'])->resolve($request),
            'available_courses' => AvailableCourseOfferingResource::collection($workspace['available_courses'])->resolve($request),
            'summary' => $workspace['summary'] === null
                ? null
                : (new StudentRegistrationSummaryResource($workspace['summary']))->resolve($request),
            'hours' => $workspace['hours'],
            'request' => $workspace['request'],
        ]);
    }

    public function addItem(Request $request, CourseOffering $courseOffering): JsonResponse
    {
        $student = $this->authenticatedStudent($request);
        $result = $this->requests->addItem($student, $courseOffering, $request->user());

        return $this->successResponse(
            ['request' => $this->requests->studentRequestView($student, $result)],
            'تمت إضافة المادة إلى طلب التسجيل.',
            201
        );
    }

    public function removeItem(Request $request, StudentRegistrationRequestItem $requestItem): JsonResponse
    {
        $student = $this->authenticatedStudent($request);
        $result = $this->requests->removeItem($student, $requestItem, $request->user());

        return $this->successResponse(
            ['request' => $this->requests->studentRequestView($student, $result)],
            'تمت إزالة المادة من طلب التسجيل.'
        );
    }

    public function updateRequest(Request $request): JsonResponse
    {
        $student = $this->authenticatedStudent($request);
        $validated = $request->validate([
            'student_notes' => ['nullable', 'string', 'max:1000'],
            'semester_id' => ['sometimes', 'integer', 'min:1', 'exists:semesters,semester_id'],
        ]);

        $result = $this->requests->updateNotes(
            $student,
            $validated['student_notes'] ?? null,
            $request->user(),
            isset($validated['semester_id']) ? (int) $validated['semester_id'] : null
        );

        return $this->successResponse(
            ['request' => $this->requests->studentRequestView($student, $result)],
            'تم حفظ ملاحظات الطلب.'
        );
    }

    public function submit(Request $request): JsonResponse
    {
        $student = $this->authenticatedStudent($request);
        $validated = $request->validate([
            'semester_id' => ['sometimes', 'integer', 'min:1', 'exists:semesters,semester_id'],
        ]);

        $result = $this->requests->submit(
            $student,
            $request->user(),
            isset($validated['semester_id']) ? (int) $validated['semester_id'] : null
        );

        return $this->successResponse(
            ['request' => $this->requests->studentRequestView($student, $result)],
            'تم إرسال طلب التسجيل إلى المرشد الأكاديمي.'
        );
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
