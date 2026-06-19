<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\CoursePrerequisite\StoreCoursePrerequisiteRequest;
use App\Http\Requests\CoursePrerequisite\UpdateCoursePrerequisiteRequest;
use App\Http\Resources\CoursePrerequisiteResource;
use App\Models\CoursePrerequisite;

class CoursePrerequisiteController extends ApiController
{
    protected function modelClass(): string
    {
        return CoursePrerequisite::class;
    }

    protected function resourceClass(): string
    {
        return CoursePrerequisiteResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreCoursePrerequisiteRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateCoursePrerequisiteRequest::class;
    }
}
