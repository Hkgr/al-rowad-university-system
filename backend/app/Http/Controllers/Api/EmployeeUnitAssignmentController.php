<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\EmployeeUnitAssignment\StoreEmployeeUnitAssignmentRequest;
use App\Http\Requests\EmployeeUnitAssignment\UpdateEmployeeUnitAssignmentRequest;
use App\Http\Resources\EmployeeUnitAssignmentResource;
use App\Models\EmployeeUnitAssignment;

class EmployeeUnitAssignmentController extends ApiController
{
    protected function modelClass(): string
    {
        return EmployeeUnitAssignment::class;
    }

    protected function resourceClass(): string
    {
        return EmployeeUnitAssignmentResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreEmployeeUnitAssignmentRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateEmployeeUnitAssignmentRequest::class;
    }
}
