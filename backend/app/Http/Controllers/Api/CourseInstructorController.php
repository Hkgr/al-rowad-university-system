<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\CourseInstructor\StoreCourseInstructorRequest;
use App\Http\Requests\CourseInstructor\UpdateCourseInstructorRequest;
use App\Http\Resources\CourseInstructorResource;
use App\Models\CourseInstructor;

class CourseInstructorController extends ApiController
{
    protected function modelClass(): string
    {
        return CourseInstructor::class;
    }

    protected function resourceClass(): string
    {
        return CourseInstructorResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreCourseInstructorRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateCourseInstructorRequest::class;
    }
}
