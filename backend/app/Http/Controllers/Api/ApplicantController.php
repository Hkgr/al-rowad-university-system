<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Applicant\StoreApplicantRequest;
use App\Http\Requests\Applicant\UpdateApplicantRequest;
use App\Http\Resources\ApplicantResource;
use App\Models\Applicant;

class ApplicantController extends ApiController
{
    protected function modelClass(): string
    {
        return Applicant::class;
    }

    protected function resourceClass(): string
    {
        return ApplicantResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreApplicantRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateApplicantRequest::class;
    }
}
