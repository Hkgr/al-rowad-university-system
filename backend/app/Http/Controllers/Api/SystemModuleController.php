<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\SystemModule\StoreSystemModuleRequest;
use App\Http\Requests\SystemModule\UpdateSystemModuleRequest;
use App\Http\Resources\SystemModuleResource;
use App\Models\SystemModule;

class SystemModuleController extends ApiController
{
    protected function modelClass(): string
    {
        return SystemModule::class;
    }

    protected function resourceClass(): string
    {
        return SystemModuleResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreSystemModuleRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateSystemModuleRequest::class;
    }
}
