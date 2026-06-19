<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StudentCreditLimit\StoreStudentCreditLimitRequest;
use App\Http\Requests\StudentCreditLimit\UpdateStudentCreditLimitRequest;
use App\Http\Resources\StudentCreditLimitResource;
use App\Models\StudentCreditLimit;

class StudentCreditLimitController extends ApiController
{
    protected function modelClass(): string
    {
        return StudentCreditLimit::class;
    }

    protected function resourceClass(): string
    {
        return StudentCreditLimitResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreStudentCreditLimitRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateStudentCreditLimitRequest::class;
    }
}
