<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StudentStatus\StoreStudentStatusRequest;
use App\Http\Requests\StudentStatus\UpdateStudentStatusRequest;
use App\Http\Resources\StudentStatusResource;
use App\Models\StudentStatus;

class StudentStatusController extends ApiController
{
    protected function modelClass(): string
    {
        return StudentStatus::class;
    }

    protected function resourceClass(): string
    {
        return StudentStatusResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreStudentStatusRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateStudentStatusRequest::class;
    }
}
