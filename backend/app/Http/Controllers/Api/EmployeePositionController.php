<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\EmployeePosition\StoreEmployeePositionRequest;
use App\Http\Requests\EmployeePosition\UpdateEmployeePositionRequest;
use App\Http\Resources\EmployeePositionResource;
use App\Models\EmployeePosition;

class EmployeePositionController extends ApiController
{
    protected function modelClass(): string
    {
        return EmployeePosition::class;
    }

    protected function resourceClass(): string
    {
        return EmployeePositionResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreEmployeePositionRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateEmployeePositionRequest::class;
    }
}
