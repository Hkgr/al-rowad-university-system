<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\ResultStatus\StoreResultStatusRequest;
use App\Http\Requests\ResultStatus\UpdateResultStatusRequest;
use App\Http\Resources\ResultStatusResource;
use App\Models\ResultStatus;

class ResultStatusController extends ApiController
{
    protected function modelClass(): string
    {
        return ResultStatus::class;
    }

    protected function resourceClass(): string
    {
        return ResultStatusResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreResultStatusRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateResultStatusRequest::class;
    }
}
