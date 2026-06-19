<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StudentAcademicTerm\StoreStudentAcademicTermRequest;
use App\Http\Requests\StudentAcademicTerm\UpdateStudentAcademicTermRequest;
use App\Http\Resources\StudentAcademicTermResource;
use App\Models\StudentAcademicTerm;

class StudentAcademicTermController extends ApiController
{
    protected function modelClass(): string
    {
        return StudentAcademicTerm::class;
    }

    protected function resourceClass(): string
    {
        return StudentAcademicTermResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreStudentAcademicTermRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateStudentAcademicTermRequest::class;
    }
}
