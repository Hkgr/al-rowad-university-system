<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;

class RoleController extends ApiController
{
    protected function modelClass(): string
    {
        return Role::class;
    }

    protected function resourceClass(): string
    {
        return RoleResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreRoleRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateRoleRequest::class;
    }
}
