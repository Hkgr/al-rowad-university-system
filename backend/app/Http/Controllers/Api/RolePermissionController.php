<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\RolePermission\StoreRolePermissionRequest;
use App\Http\Requests\RolePermission\UpdateRolePermissionRequest;
use App\Http\Resources\RolePermissionResource;
use App\Models\RolePermission;

class RolePermissionController extends ApiController
{
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
