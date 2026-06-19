<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\MeetingAttendee\StoreMeetingAttendeeRequest;
use App\Http\Requests\MeetingAttendee\UpdateMeetingAttendeeRequest;
use App\Http\Resources\MeetingAttendeeResource;
use App\Models\MeetingAttendee;

class MeetingAttendeeController extends ApiController
{
    protected function modelClass(): string
    {
        return MeetingAttendee::class;
    }

    protected function resourceClass(): string
    {
        return MeetingAttendeeResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreMeetingAttendeeRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateMeetingAttendeeRequest::class;
    }
}
