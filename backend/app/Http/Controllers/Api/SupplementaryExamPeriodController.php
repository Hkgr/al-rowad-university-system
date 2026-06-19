<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\SupplementaryExamPeriod\StoreSupplementaryExamPeriodRequest;
use App\Http\Requests\SupplementaryExamPeriod\UpdateSupplementaryExamPeriodRequest;
use App\Http\Resources\SupplementaryExamPeriodResource;
use App\Models\SupplementaryExamPeriod;

class SupplementaryExamPeriodController extends ApiController
{
    protected function modelClass(): string
    {
        return SupplementaryExamPeriod::class;
    }

    protected function resourceClass(): string
    {
        return SupplementaryExamPeriodResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreSupplementaryExamPeriodRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateSupplementaryExamPeriodRequest::class;
    }
}
