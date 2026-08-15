<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\StudentCourseRegistrationResource;
use App\Models\StudentCourseRegistration;
use App\Services\AcademicAuthorizationService;
use App\Services\DataScopeService;
use Illuminate\Http\JsonResponse;

class StudentCourseRegistrationController extends ApiController
{
    public function index(): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('registration.view'), 403);
        $registrations = app(DataScopeService::class)->scopeRegistrations(StudentCourseRegistration::query(), request()->user())
            ->with(['student', 'registrationStatus', 'courseOffering.course'])
            ->paginate(request()->integer('per_page', 15));

        return $this->successResponse(
            StudentCourseRegistrationResource::collection($registrations)->response(request())->getData(true)
        );
    }

    public function show($id): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('registration.view'), 403);
        $user = request()->user();
        $authorization = app(AcademicAuthorizationService::class);
        $registration = StudentCourseRegistration::query()
            ->with(['student', 'registrationStatus', 'courseOffering.course'])
            ->findOrFail($id);
        $authorization->assertStudentRecord($user, $registration->student);
        abort_unless(app(DataScopeService::class)->scopeRegistrations(StudentCourseRegistration::query(), $user)->whereKey($id)->exists(), 403);

        if ($authorization->canExposeStudentCourseResult($user, $registration)) {
            $registration->load('studentCourseResult.resultStatus');
        }

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
