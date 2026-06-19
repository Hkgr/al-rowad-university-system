<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\GradeComponent\StoreGradeComponentRequest;
use App\Http\Requests\GradeComponent\UpdateGradeComponentRequest;
use App\Http\Resources\GradeComponentResource;
use App\Models\GradeComponent;

class GradeComponentController extends ApiController
{
    protected function modelClass(): string
    {
        return GradeComponent::class;
    }

    protected function resourceClass(): string
    {
        return GradeComponentResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreGradeComponentRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateGradeComponentRequest::class;
    }
}
