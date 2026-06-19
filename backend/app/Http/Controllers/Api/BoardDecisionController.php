<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\BoardDecision\StoreBoardDecisionRequest;
use App\Http\Requests\BoardDecision\UpdateBoardDecisionRequest;
use App\Http\Resources\BoardDecisionResource;
use App\Models\BoardDecision;

class BoardDecisionController extends ApiController
{
    protected function modelClass(): string
    {
        return BoardDecision::class;
    }

    protected function resourceClass(): string
    {
        return BoardDecisionResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreBoardDecisionRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateBoardDecisionRequest::class;
    }
}
