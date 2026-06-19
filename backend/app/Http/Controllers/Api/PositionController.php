<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Position\StorePositionRequest;
use App\Http\Requests\Position\UpdatePositionRequest;
use App\Http\Resources\PositionResource;
use App\Models\Position;

class PositionController extends ApiController
{
    protected function modelClass(): string
    {
        return Position::class;
    }

    protected function resourceClass(): string
    {
        return PositionResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StorePositionRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdatePositionRequest::class;
    }
}
