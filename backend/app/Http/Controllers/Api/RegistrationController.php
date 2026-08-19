<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\RegisterStudentRequest;
use App\Http\Resources\StudentCourseRegistrationResource;
use App\Http\Resources\StudentRegistrationResultResource;
use App\Services\RegistrationService;
use App\Services\DataScopeService;
use App\Models\Student;
use App\Models\CourseOffering;
use App\Models\StudentCourseRegistration;
use Illuminate\Http\JsonResponse;

class RegistrationController extends Controller
{
    protected function successResponse(mixed $data = [], string $message = 'Operation completed successfully', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public function registerStudent(RegisterStudentRequest $request, RegistrationService $service): JsonResponse
    {
        // Retained for historical callers. Live-semester registration must
        // go through StudentRegistrationRequest -> Academic Advisor approval.
        $scope = app(DataScopeService::class);
        $student = Student::query()->findOrFail($request->integer('student_id'));
        $offering = CourseOffering::query()->findOrFail($request->integer('course_offering_id'));
        abort_unless($scope->canStaffManageRegistration($request->user(), $student, $offering), 403);
        $result = $service->registerStudent(
            $request->validated(),
            $request->user()?->user_id
        );

        return $this->successResponse(
            (new StudentRegistrationResultResource($result))->resolve($request),
            'Student registered successfully',
            201
        );
    }

    public function drop(int $id, RegistrationService $service): JsonResponse
    {
        // Retained for historical callers. Live add/drop uses student self-drop.
        $registration = $service->findOrFail($id);
        $this->authorizeRegistration($registration);
        $updatedRegistration = $service->dropRegistration($registration);

        return $this->successResponse(
            (new StudentCourseRegistrationResource($updatedRegistration))->resolve(request()),
            'Registration dropped successfully'
        );
    }

    public function withdraw(int $id, RegistrationService $service): JsonResponse
    {
        // Retained for historical callers. Live withdrawal uses the formal request.
        $registration = $service->findOrFail($id);
        $this->authorizeRegistration($registration);
        $updatedRegistration = $service->withdrawRegistration($registration);

        return $this->successResponse(
            (new StudentCourseRegistrationResource($updatedRegistration))->resolve(request()),
            'Registration withdrawn successfully'
        );
    }

    private function authorizeRegistration(StudentCourseRegistration $registration): void
    {
        $user = request()->user();
        $scope = app(DataScopeService::class);
        abort_unless($scope->canStaffManageRegistration($user, $registration->student, $registration->courseOffering), 403);
    }
}
