<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Board\StoreBoardRequest;
use App\Http\Requests\Board\UpdateBoardRequest;
use App\Http\Resources\BoardResource;
use App\Models\Board;

class BoardController extends ApiController
{
    protected function modelClass(): string
    {
        return Board::class;
    }

    protected function resourceClass(): string
    {
        return BoardResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreBoardRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateBoardRequest::class;
    }
}
