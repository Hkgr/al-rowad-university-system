<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StudentCourseResult\StoreStudentCourseResultRequest;
use App\Http\Requests\StudentCourseResult\UpdateStudentCourseResultRequest;
use App\Http\Resources\StudentCourseResultResource;
use App\Models\StudentCourseResult;

class StudentCourseResultController extends ApiController
{
    protected function modelClass(): string
    {
        return StudentCourseResult::class;
    }

    protected function resourceClass(): string
    {
        return StudentCourseResultResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreStudentCourseResultRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateStudentCourseResultRequest::class;
    }
}
