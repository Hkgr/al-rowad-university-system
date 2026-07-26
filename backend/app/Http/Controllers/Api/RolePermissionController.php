<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\RolePermission\StoreRolePermissionRequest;
use App\Http\Requests\RolePermission\UpdateRolePermissionRequest;
use App\Http\Resources\RolePermissionResource;
use App\Models\RolePermission;
use Illuminate\Http\JsonResponse;

class RolePermissionController extends ApiController
{
    public function store(StoreRolePermissionRequest $request): JsonResponse
    {
        $rolePermission = RolePermission::query()->create([
            ...$request->validated(),
            'granted_at' => now(),
        ]);

        return $this->successResponse(
            (new RolePermissionResource($rolePermission))->resolve($request),
            'Operation completed successfully',
            201
        );
    }

    protected function modelClass(): string
    {
        return RolePermission::class;
    }

    protected function resourceClass(): string
    {
        return RolePermissionResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreRolePermissionRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateRolePermissionRequest::class;
    }
}
