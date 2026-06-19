<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\UserRole\StoreUserRoleRequest;
use App\Http\Requests\UserRole\UpdateUserRoleRequest;
use App\Http\Resources\UserRoleResource;
use App\Models\UserRole;

class UserRoleController extends ApiController
{
    protected function modelClass(): string
    {
        return UserRole::class;
    }

    protected function resourceClass(): string
    {
        return UserRoleResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreUserRoleRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateUserRoleRequest::class;
    }
}
