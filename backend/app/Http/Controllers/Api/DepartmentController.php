<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Department\StoreDepartmentRequest;
use App\Http\Requests\Department\UpdateDepartmentRequest;
use App\Http\Resources\AcademicProgramResource;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Services\GradeService;
use App\Services\DataScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends ApiController
{
    protected function modelClass(): string
    {
        return Department::class;
    }

    protected function resourceClass(): string
    {
        return DepartmentResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreDepartmentRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateDepartmentRequest::class;
    }

    public function programs(Department $department): JsonResponse
    {
        abort_unless(app(DataScopeService::class)->canAccessDepartment(request()->user(), $department->department_id), 403);
        $programs = app(DataScopeService::class)->scopePrograms($department->academicPrograms(), request()->user())
            ->orderBy('program_name')
            ->paginate(request()->integer('per_page', 15));

        return $this->successResponse(
            AcademicProgramResource::collection($programs)->response(request())->getData(true)
        );
    }

    public function statistics(int $id, Request $request, GradeService $service): JsonResponse
    {
        abort_unless($request->user()->hasPermission('grades.view'), 403);
        abort_unless(app(\App\Services\DataScopeService::class)->canAccessDepartment($request->user(), $id), 403);
        $validated = $request->validate([
            'academic_year_id' => ['sometimes', 'nullable', 'integer', 'exists:academic_years,academic_year_id'],
            'semester_id' => ['sometimes', 'nullable', 'integer', 'exists:semesters,semester_id'],
        ]);

        $statistics = $service->getDepartmentStatistics(
            $id,
            $validated['academic_year_id'] ?? null,
            $validated['semester_id'] ?? null,
            $request->user(),
        );

        return $this->successResponse($statistics);
    }
}
