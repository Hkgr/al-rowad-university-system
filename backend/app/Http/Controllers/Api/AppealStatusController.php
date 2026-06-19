<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\AppealStatus\StoreAppealStatusRequest;
use App\Http\Requests\AppealStatus\UpdateAppealStatusRequest;
use App\Http\Resources\AppealStatusResource;
use App\Models\AppealStatus;

class AppealStatusController extends ApiController
{
    protected function modelClass(): string
    {
        return AppealStatus::class;
    }

    protected function resourceClass(): string
    {
        return AppealStatusResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreAppealStatusRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateAppealStatusRequest::class;
    }
}
