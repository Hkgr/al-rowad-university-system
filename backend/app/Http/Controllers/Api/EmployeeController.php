<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;

class EmployeeController extends ApiController
{
    protected function modelClass(): string
    {
        return Employee::class;
    }

    protected function resourceClass(): string
    {
        return EmployeeResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreEmployeeRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateEmployeeRequest::class;
    }
}
