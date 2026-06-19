<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\CourseDepartment\StoreCourseDepartmentRequest;
use App\Http\Requests\CourseDepartment\UpdateCourseDepartmentRequest;
use App\Http\Resources\CourseDepartmentResource;
use App\Models\CourseDepartment;

class CourseDepartmentController extends ApiController
{
    protected function modelClass(): string
    {
        return CourseDepartment::class;
    }

    protected function resourceClass(): string
    {
        return CourseDepartmentResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreCourseDepartmentRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateCourseDepartmentRequest::class;
    }
}
