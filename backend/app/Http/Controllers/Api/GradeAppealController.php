<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\GradeAppeal\StoreGradeAppealRequest;
use App\Http\Requests\GradeAppeal\UpdateGradeAppealRequest;
use App\Http\Resources\GradeAppealResource;
use App\Models\GradeAppeal;

class GradeAppealController extends ApiController
{
    protected function modelClass(): string
    {
        return GradeAppeal::class;
    }

    protected function resourceClass(): string
    {
        return GradeAppealResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreGradeAppealRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateGradeAppealRequest::class;
    }
}
