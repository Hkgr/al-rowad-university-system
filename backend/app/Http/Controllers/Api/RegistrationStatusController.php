<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\RegistrationStatus\StoreRegistrationStatusRequest;
use App\Http\Requests\RegistrationStatus\UpdateRegistrationStatusRequest;
use App\Http\Resources\RegistrationStatusResource;
use App\Models\RegistrationStatus;

class RegistrationStatusController extends ApiController
{
    protected function modelClass(): string
    {
        return RegistrationStatus::class;
    }

    protected function resourceClass(): string
    {
        return RegistrationStatusResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreRegistrationStatusRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateRegistrationStatusRequest::class;
    }
}
