<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\BoardMeeting\StoreBoardMeetingRequest;
use App\Http\Requests\BoardMeeting\UpdateBoardMeetingRequest;
use App\Http\Resources\BoardMeetingResource;
use App\Models\BoardMeeting;

class BoardMeetingController extends ApiController
{
    protected function modelClass(): string
    {
        return BoardMeeting::class;
    }

    protected function resourceClass(): string
    {
        return BoardMeetingResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreBoardMeetingRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateBoardMeetingRequest::class;
    }
}
