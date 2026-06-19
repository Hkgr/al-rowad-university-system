<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\AdmissionApplication\StoreAdmissionApplicationRequest;
use App\Http\Requests\AdmissionApplication\UpdateAdmissionApplicationRequest;
use App\Http\Resources\AdmissionApplicationResource;
use App\Models\AdmissionApplication;

class AdmissionApplicationController extends ApiController
{
    protected function modelClass(): string
    {
        return AdmissionApplication::class;
    }

    protected function resourceClass(): string
    {
        return AdmissionApplicationResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreAdmissionApplicationRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateAdmissionApplicationRequest::class;
    }
}
