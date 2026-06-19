<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Permission\StorePermissionRequest;
use App\Http\Requests\Permission\UpdatePermissionRequest;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;

class PermissionController extends ApiController
{
    protected function modelClass(): string
    {
        return Permission::class;
    }

    protected function resourceClass(): string
    {
        return PermissionResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StorePermissionRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdatePermissionRequest::class;
    }
}
