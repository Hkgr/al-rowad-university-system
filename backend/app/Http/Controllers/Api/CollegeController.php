<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\College\StoreCollegeRequest;
use App\Http\Requests\College\UpdateCollegeRequest;
use App\Http\Resources\CollegeResource;
use App\Http\Resources\DepartmentResource;
use App\Models\College;
use Illuminate\Http\JsonResponse;

class CollegeController extends ApiController
{
    public function store(): JsonResponse
    {
        $entity = app(\App\Services\AcademicStructureEntityService::class)->save(request()->user(), 'colleges', null, request()->all());
        return $this->successResponse((new CollegeResource($entity))->resolve(request()), status: 201);
    }

    public function update($id): JsonResponse
    {
        $entity = app(\App\Services\AcademicStructureEntityService::class)->save(request()->user(), 'colleges', (int) $id, request()->all());
        return $this->successResponse((new CollegeResource($entity))->resolve(request()));
    }

    public function destroy($id): JsonResponse
    {
        app(\App\Services\AcademicStructureEntityService::class)->delete(request()->user(), 'colleges', (int) $id);
        return $this->successResponse();
    }

    protected function modelClass(): string
    {
        return College::class;
    }

    protected function resourceClass(): string
    {
        return CollegeResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreCollegeRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateCollegeRequest::class;
    }

    public function departments(College $college): JsonResponse
    {
        $departments = $college->departments()
            ->orderBy('department_name')
            ->paginate(request()->integer('per_page', 15));

        return $this->successResponse(
            DepartmentResource::collection($departments)->response(request())->getData(true)
        );
    }
}
