<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\ProgramCourse\StoreProgramCourseRequest;
use App\Http\Requests\ProgramCourse\UpdateProgramCourseRequest;
use App\Http\Resources\ProgramCourseResource;
use App\Models\ProgramCourse;

class ProgramCourseController extends ApiController
{
    protected function modelClass(): string
    {
        return ProgramCourse::class;
    }

    protected function resourceClass(): string
    {
        return ProgramCourseResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreProgramCourseRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateProgramCourseRequest::class;
    }
}
