<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StudentGradeComponent\StoreStudentGradeComponentRequest;
use App\Http\Requests\StudentGradeComponent\UpdateStudentGradeComponentRequest;
use App\Http\Resources\StudentGradeComponentResource;
use App\Models\StudentGradeComponent;

class StudentGradeComponentController extends ApiController
{
    protected function modelClass(): string
    {
        return StudentGradeComponent::class;
    }

    protected function resourceClass(): string
    {
        return StudentGradeComponentResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreStudentGradeComponentRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateStudentGradeComponentRequest::class;
    }
}
