<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentRequirementProgressResource;
use App\Models\Student;
use App\Services\AcademicRequirementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class StudentSelfRequirementController extends Controller
{
    public function __construct(private AcademicRequirementService $requirements)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $student = $this->authenticatedStudent($request);

        return $this->successResponse(
            (new StudentRequirementProgressResource(
                $this->requirements->getStudentRequirementProgress($student)
            ))->resolve($request)
        );
    }

    private function authenticatedStudent(Request $request): Student
    {
        $user = $request->user();
        if ($user === null || $user->student_id === null) {
            throw new AccessDeniedHttpException('يجب أن يكون للحساب سجل طالب لعرض متطلبات البرنامج.');
        }

        $student = Student::query()->find($user->student_id);
        if ($student === null) {
            throw new AccessDeniedHttpException('يجب أن يكون للحساب سجل طالب لعرض متطلبات البرنامج.');
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
