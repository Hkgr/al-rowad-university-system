<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\StudentCourseResultResource;
use App\Models\StudentCourseResult;
use App\Services\AcademicAuthorizationService;
use App\Services\DataScopeService;
use App\Services\GradeService;
use Illuminate\Http\JsonResponse;

class StudentCourseResultController extends ApiController
{
    public function index(): JsonResponse
    {
        $user = request()->user();
        abort_unless($user->hasPermission('grades.view'), 403);

        $query = app(DataScopeService::class)->scopeResourceQuery(StudentCourseResult::query(), $user)
            ->with('studentCourseRegistration.registrationStatus');

        if (app(AcademicAuthorizationService::class)->isRestrictedToOfficialStudentGrades($user)) {
            $query = app(GradeService::class)->scopeOfficialApprovedResults($query, (int) $user->student_id);
        }

        $results = $query->paginate(request()->integer('per_page', 15));

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
