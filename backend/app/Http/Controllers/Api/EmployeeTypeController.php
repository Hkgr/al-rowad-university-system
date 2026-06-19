<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\EmployeeType\StoreEmployeeTypeRequest;
use App\Http\Requests\EmployeeType\UpdateEmployeeTypeRequest;
use App\Http\Resources\EmployeeTypeResource;
use App\Models\EmployeeType;

class EmployeeTypeController extends ApiController
{
    protected function modelClass(): string
    {
        return EmployeeType::class;
    }

    protected function resourceClass(): string
    {
        return EmployeeTypeResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreEmployeeTypeRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateEmployeeTypeRequest::class;
    }
}
