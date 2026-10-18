<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\AdmissionApplication\StoreAdmissionApplicationRequest;
use App\Http\Requests\AdmissionApplication\UpdateAdmissionApplicationRequest;
use App\Http\Resources\AdmissionApplicationResource;
use App\Models\AdmissionApplication;

class AdmissionApplicationController extends ApiController
{
    public function store(): \Illuminate\Http\JsonResponse
    {
        return \App\Services\AcademicPlanContext::transaction(fn () => parent::store());
    }

    public function update($id): \Illuminate\Http\JsonResponse
    {
        return \App\Services\AcademicPlanContext::transaction(fn () => parent::update($id));
    }

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
