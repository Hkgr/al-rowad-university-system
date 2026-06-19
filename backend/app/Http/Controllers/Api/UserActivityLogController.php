<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\UserActivityLog\StoreUserActivityLogRequest;
use App\Http\Requests\UserActivityLog\UpdateUserActivityLogRequest;
use App\Http\Resources\UserActivityLogResource;
use App\Models\UserActivityLog;

class UserActivityLogController extends ApiController
{
    protected function modelClass(): string
    {
        return UserActivityLog::class;
    }

    protected function resourceClass(): string
    {
        return UserActivityLogResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreUserActivityLogRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateUserActivityLogRequest::class;
    }
}
