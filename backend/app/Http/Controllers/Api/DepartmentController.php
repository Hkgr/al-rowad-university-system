<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Department\StoreDepartmentRequest;
use App\Http\Requests\Department\UpdateDepartmentRequest;
use App\Http\Resources\AcademicProgramResource;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Http\JsonResponse;

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
        $programs = $department->academicPrograms()
            ->orderBy('program_name')
            ->paginate(request()->integer('per_page', 15));

        return $this->successResponse(
            AcademicProgramResource::collection($programs)->response(request())->getData(true)
        );
    }
}
