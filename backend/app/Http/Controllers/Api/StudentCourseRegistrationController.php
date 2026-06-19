<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StudentCourseRegistration\StoreStudentCourseRegistrationRequest;
use App\Http\Requests\StudentCourseRegistration\UpdateStudentCourseRegistrationRequest;
use App\Http\Resources\StudentCourseRegistrationResource;
use App\Models\StudentCourseRegistration;

class StudentCourseRegistrationController extends ApiController
{
    protected function modelClass(): string
    {
        return StudentCourseRegistration::class;
    }

    protected function resourceClass(): string
    {
        return StudentCourseRegistrationResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreStudentCourseRegistrationRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateStudentCourseRegistrationRequest::class;
    }
}
