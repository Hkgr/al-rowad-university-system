<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\SupplementaryExamResult\StoreSupplementaryExamResultRequest;
use App\Http\Requests\SupplementaryExamResult\UpdateSupplementaryExamResultRequest;
use App\Http\Resources\SupplementaryExamResultResource;
use App\Models\SupplementaryExamResult;

class SupplementaryExamResultController extends ApiController
{
    protected function modelClass(): string
    {
        return SupplementaryExamResult::class;
    }

    protected function resourceClass(): string
    {
        return SupplementaryExamResultResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreSupplementaryExamResultRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateSupplementaryExamResultRequest::class;
    }
}
