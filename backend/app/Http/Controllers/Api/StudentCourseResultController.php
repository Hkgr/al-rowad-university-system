<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\StudentCourseResultResource;
use App\Models\StudentCourseResult;
use App\Services\AcademicAuthorizationService;
use Illuminate\Http\JsonResponse;
use App\Services\DataScopeService;

class StudentCourseResultController extends ApiController
{
    public function index(): JsonResponse
    {
        abort_unless(request()->user()->hasPermission('grades.view'), 403);

        $results = app(DataScopeService::class)->scopeResourceQuery(StudentCourseResult::query(), request()->user())
            ->with('studentCourseRegistration.registrationStatus')
            ->paginate(request()->integer('per_page', 15));

        return $this->successResponse(
            StudentCourseResultResource::collection($results)->response(request())->getData(true)
        );
    }

    public function show($id): JsonResponse
    {
        $result = StudentCourseResult::query()
            ->with('studentCourseRegistration.registrationStatus')
            ->findOrFail($id);

        app(AcademicAuthorizationService::class)->assertCanViewGrades(
            request()->user(),
            $result->studentCourseRegistration
        );

        return $this->successResponse(
            (new StudentCourseResultResource($result))->resolve(request())
        );
    }

    protected function modelClass(): string
    {
        return StudentCourseResult::class;
    }

    protected function resourceClass(): string
    {
        return StudentCourseResultResource::class;
    }

    protected function storeRequestClass(): string { return ''; }

    protected function updateRequestClass(): string { return ''; }
}
