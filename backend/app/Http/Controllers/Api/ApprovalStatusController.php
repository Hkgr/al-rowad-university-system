<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\ApprovalStatus\StoreApprovalStatusRequest;
use App\Http\Requests\ApprovalStatus\UpdateApprovalStatusRequest;
use App\Http\Resources\ApprovalStatusResource;
use App\Models\ApprovalStatus;

class ApprovalStatusController extends ApiController
{
    protected function modelClass(): string
    {
        return ApprovalStatus::class;
    }

    protected function resourceClass(): string
    {
        return ApprovalStatusResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreApprovalStatusRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateApprovalStatusRequest::class;
    }
}
