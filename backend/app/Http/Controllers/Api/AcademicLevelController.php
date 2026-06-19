<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\AcademicLevel\StoreAcademicLevelRequest;
use App\Http\Requests\AcademicLevel\UpdateAcademicLevelRequest;
use App\Http\Resources\AcademicLevelResource;
use App\Models\AcademicLevel;

class AcademicLevelController extends ApiController
{
    protected function modelClass(): string
    {
        return AcademicLevel::class;
    }

    protected function resourceClass(): string
    {
        return AcademicLevelResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreAcademicLevelRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateAcademicLevelRequest::class;
    }
}
