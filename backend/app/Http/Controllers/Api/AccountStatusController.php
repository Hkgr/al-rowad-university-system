<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\AccountStatus\StoreAccountStatusRequest;
use App\Http\Requests\AccountStatus\UpdateAccountStatusRequest;
use App\Http\Resources\AccountStatusResource;
use App\Models\AccountStatus;

class AccountStatusController extends ApiController
{
    protected function modelClass(): string
    {
        return AccountStatus::class;
    }

    protected function resourceClass(): string
    {
        return AccountStatusResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreAccountStatusRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateAccountStatusRequest::class;
    }
}
