<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\RegisterStudentRequest;
use App\Http\Resources\StudentCourseRegistrationResource;
use App\Http\Resources\StudentRegistrationResultResource;
use App\Services\RegistrationService;
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
        $registration = $service->findOrFail($id);
        $updatedRegistration = $service->dropRegistration($registration);

        return $this->successResponse(
            (new StudentCourseRegistrationResource($updatedRegistration))->resolve(request()),
            'Registration dropped successfully'
        );
    }

    public function withdraw(int $id, RegistrationService $service): JsonResponse
    {
        $registration = $service->findOrFail($id);
        $updatedRegistration = $service->withdrawRegistration($registration);

        return $this->successResponse(
            (new StudentCourseRegistrationResource($updatedRegistration))->resolve(request()),
            'Registration withdrawn successfully'
        );
    }
}
