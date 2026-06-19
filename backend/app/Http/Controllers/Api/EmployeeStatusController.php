<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\EmployeeStatus\StoreEmployeeStatusRequest;
use App\Http\Requests\EmployeeStatus\UpdateEmployeeStatusRequest;
use App\Http\Resources\EmployeeStatusResource;
use App\Models\EmployeeStatus;

class EmployeeStatusController extends ApiController
{
    protected function modelClass(): string
    {
        return EmployeeStatus::class;
    }

    protected function resourceClass(): string
    {
        return EmployeeStatusResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreEmployeeStatusRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateEmployeeStatusRequest::class;
    }
}
