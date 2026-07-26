<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\StudentCourseRegistrationResource;
use App\Models\StudentCourseRegistration;
use App\Services\AcademicAuthorizationService;
use Illuminate\Http\JsonResponse;

class StudentCourseRegistrationController extends ApiController
{
    public function index(): JsonResponse
    {
        app(AcademicAuthorizationService::class)->assertCanSearchStudents(request()->user());
        $registrations = StudentCourseRegistration::query()
            ->with(['student', 'registrationStatus', 'courseOffering.course'])
            ->paginate(request()->integer('per_page', 15));

        return $this->successResponse(
            StudentCourseRegistrationResource::collection($registrations)->response(request())->getData(true)
        );
    }

    public function show($id): JsonResponse
    {
        $registration = StudentCourseRegistration::query()
            ->with(['student', 'registrationStatus', 'courseOffering.course', 'studentCourseResult.resultStatus'])
            ->findOrFail($id);
        app(AcademicAuthorizationService::class)->assertStudentRecord(request()->user(), $registration->student);

        return $this->successResponse(
            (new StudentCourseRegistrationResource($registration))->resolve(request())
        );
    }

    protected function modelClass(): string
    {
        return StudentCourseRegistration::class;
    }

    protected function resourceClass(): string
    {
        return StudentCourseRegistrationResource::class;
    }

    protected function storeRequestClass(): string { return ''; }

    protected function updateRequestClass(): string { return ''; }
}
