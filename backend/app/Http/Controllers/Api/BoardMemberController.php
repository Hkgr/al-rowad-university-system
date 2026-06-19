<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\BoardMember\StoreBoardMemberRequest;
use App\Http\Requests\BoardMember\UpdateBoardMemberRequest;
use App\Http\Resources\BoardMemberResource;
use App\Models\BoardMember;

class BoardMemberController extends ApiController
{
    protected function modelClass(): string
    {
        return BoardMember::class;
    }

    protected function resourceClass(): string
    {
        return BoardMemberResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreBoardMemberRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateBoardMemberRequest::class;
    }
}
